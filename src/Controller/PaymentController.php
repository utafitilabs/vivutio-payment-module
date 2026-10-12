<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio payment module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Payment\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Payment\Enum\MethodEnum;
use Vivutio\Payment\Exception\InvalidPaymentException;
use Vivutio\Payment\Service\PaymentService;

/**
 * Payments: what is owed, by status, and a charge's page with its payments,
 * recording one and striking one out.
 */
final readonly class PaymentController
{
    public const string REGISTER = 'payment_charges';
    public const string SHOW = 'payment_charge';
    public const string RECORD_PAYMENT = 'payment_record';
    public const string STRIKE = 'payment_strike';

    public const string READ = 'payments.read';
    public const string RECORD = 'payments.record';
    public const string MANAGE = 'payments.manage';

    /** A charge's key as a module makes it: "tour_booking:01a…". */
    private const string KEY = '[a-z0-9_]+:[A-Za-z0-9_.-]+';

    private const array STATES = ['owed' => 'Owed', 'overdue' => 'Overdue', 'paid' => 'Paid'];

    public function __construct(
        private Environment $twig,
        private PaymentService $service,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/payments', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        $state = $request->query->getString('state');
        $state = isset(self::STATES[$state]) ? $state : null;
        $all = $this->service->standing();
        $matches = static fn (array $row, string $state): bool => match ($state) {
            'owed' => $row['owed'] > 0,
            'overdue' => PaymentService::OVERDUE === $row['state'],
            default => PaymentService::PAID === $row['state'],
        };
        $owed = PaymentService::owed($all);
        $overdue = PaymentService::owed(array_values(array_filter($all, static fn (array $row): bool => $matches($row, 'overdue'))));
        $owedCount = \count(array_filter($all, static fn (array $row): bool => $matches($row, 'owed')));
        $overdueCount = \count(array_filter($all, static fn (array $row): bool => $matches($row, 'overdue')));
        $month = $this->service->paidThisMonth();
        $shown = array_values(array_filter($all, static fn (array $row): bool => null === $state || $matches($row, $state)));
        usort($shown, static fn (array $a, array $b): int => [PaymentService::PAID === $a['state'], $a['charge']->dueOn] <=> [PaymentService::PAID === $b['state'], $b['charge']->dueOn]);

        return new Response($this->twig->render('@VivutioPayment/payments/index.html.twig', [
            'rows' => $shown,
            'total' => \count($all),
            'counts' => ['owed' => $owedCount, 'overdue' => $overdueCount, 'paid' => \count(array_filter($all, static fn (array $row): bool => $matches($row, 'paid')))],
            'states' => self::STATES,
            'state' => $state,
            'band' => [
                ['Owed to you', PaymentService::amounts($owed, 'None'), self::count($owedCount, 'charge')],
                ['Overdue', PaymentService::amounts($overdue, 'None'), self::count($overdueCount, 'charge')],
                ['Paid this month', PaymentService::amounts($month['amounts'], 'None'), self::count($month['count'], 'payment')],
            ],
        ]));
    }

    #[Route('/payments/{key}', name: self::SHOW, requirements: ['key' => self::KEY], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(string $key): Response
    {
        return $this->chargePage($key);
    }

    #[Route('/payments/{key}/record', name: self::RECORD_PAYMENT, requirements: ['key' => self::KEY], methods: ['POST'])]
    #[IsGranted(self::RECORD)]
    public function record(Request $request, #[CurrentUser] UserInterface $user, string $key): Response
    {
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('payment_record', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->chargePage($key, $sent, expired: true);
        }

        try {
            $this->service->record($key, $sent, self::nameOf($user));
        } catch (InvalidPaymentException $refusal) {
            return $this->chargePage($key, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::SHOW, ['key' => $key]));
    }

    #[Route('/payments/{key}/strike', name: self::STRIKE, requirements: ['key' => self::KEY], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function strike(Request $request, #[CurrentUser] UserInterface $user, string $key): Response
    {
        $sent = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('payment_strike', $sent->getString('_token')))) {
            return $this->chargePage($key, expired: true);
        }
        $payment = null;
        foreach ($this->service->paymentsOn($key) as $one) {
            if ((string) $one->getUuid() === $sent->getString('which')) {
                $payment = $one;
            }
        }

        try {
            if (null === $payment) {
                throw new InvalidPaymentException('which', 'Choose a payment recorded on this charge.');
            }
            $this->service->strike($payment, $sent->getString('reason'), self::nameOf($user));
        } catch (InvalidPaymentException $refusal) {
            return $this->chargePage($key, [], [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::SHOW, ['key' => $key]));
    }

    /** "1 charge", "2 charges", "no charges". */
    private static function count(int $n, string $word): string
    {
        return (0 === $n ? 'no' : (string) $n).' '.$word.(1 === $n ? '' : 's');
    }

    private static function nameOf(UserInterface $user): string
    {
        return $user instanceof User ? $user->getFullName() : $user->getUserIdentifier();
    }

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function chargePage(string $key, array $sent = [], array $wrong = [], bool $expired = false): Response
    {
        $standing = $this->service->one($key) ?? throw new NotFoundHttpException('Nothing is charged under this key.');
        $text = static fn (string $field, string $default): string => \is_string($sent[$field] ?? null) ? $sent[$field] : $default;
        $charge = $standing['charge'];

        return new Response($this->twig->render('@VivutioPayment/payments/charge.html.twig', [
            'row' => $standing,
            'partner' => $this->service->partnerOf($charge),
            'payments' => $this->service->paymentsOn($key),
            'methods' => MethodEnum::cases(),
            'typed' => [
                'amount' => $text('amount', number_format($standing['owed'] / 100, 2, '.', '')),
                'received' => $text('received', $this->clock->now()->format('Y-m-d')),
                'method' => $text('method', MethodEnum::BankTransfer->value),
                'reference' => $text('reference', ''),
                'note' => $text('note', ''),
            ],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
