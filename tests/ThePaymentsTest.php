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

namespace Vivutio\Payment\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\Payment;
use Vivutio\Contracts\Partner\PaymentsInterface;
use Vivutio\Payment\Entity\RecordedPayment;
use Vivutio\Payment\Enum\MethodEnum;
use Vivutio\Payment\Tests\Application\StandIn\StandInCharges;

/**
 * Payments by hand, as ruled (11 October 2026) and drawn (vivutio-designs
 * payments/): what the organization is owed, gathered by the core from every
 * module that charges, and the payments received against it, each recorded
 * as it came, struck out but never deleted.
 */
final class ThePaymentsTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-11 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
        $this->em()->persist((new Partner('Savanna Trails Travel', PartnerKindEnum::TourOperator, 'KE', 'desk@savanna-trails.example'))->setCreditDays(30));
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        StandInCharges::$besides = [];
        parent::tearDown();
    }

    public function testWhatIsOwedIsListedFromEveryModuleThatCharges(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertSame('/payments', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Payments')->attr('href'));
        $page = $this->browser->request('GET', '/payments');
        self::assertSame(['USD 11,520.78 2 charges', 'USD 3,912.20 1 charge', 'None no payments'], $this->band($page));
        self::assertSame(['All 2', 'Owed 2', 'Overdue 1', 'Paid 0'], $this->chips($page));
        self::assertSame([
            ['NCS-0004 Northern Circuit Safari, 18 – 23 Oct 2026', 'Kweka party Tour booking', 'Sat 10 Oct 2026', 'USD 3,912.20', 'USD 0.00', 'USD 3,912.20', 'Overdue'],
            ['NCS-0002 Northern Circuit Safari, 2 – 7 Aug 2027', 'Savanna Trails Travel Tour booking', 'Thu 5 Nov 2026', 'USD 7,608.58', 'USD 0.00', 'USD 7,608.58', 'Unpaid'],
        ], $this->rows($page));
    }

    /** Nothing owed, late or paid reads as none, in no currency of its own. */
    public function testNothingReadsAsNone(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-4');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '3912.20', 'received' => '2026-10-11', 'method' => 'cash', 'reference' => '', 'note' => '']));

        self::assertSame(['USD 7,608.58 1 charge', 'None no charges', 'USD 3,912.20 1 payment'], $this->band($this->browser->request('GET', '/payments')));
    }

    /**
     * A ticket voided or a hold let go at no charge leaves a charge of nothing:
     * nothing is owed on it and nothing was paid, so it is not in the register.
     * Something paid against it is money held, and the charge stays.
     */
    public function testAChargeOfNothingIsNotListedUnlessSomethingWasPaidAgainstIt(): void
    {
        StandInCharges::$besides = [new Charge('stand_in:ncs-5', 'NCS-0005', 'Tour booking', 'Northern Circuit Safari, voided', null, 'Lema party', 'USD', 0, new \DateTimeImmutable('2026-09-20'), new \DateTimeImmutable('2026-10-01'))];
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        $page = $this->browser->request('GET', '/payments');
        self::assertSame(['All 2', 'Owed 2', 'Overdue 1', 'Paid 0'], $this->chips($page));
        self::assertSame(['NCS-0004', 'NCS-0002'], $page->filter('tr[data-charge] td:first-child b')->each(fn (Crawler $b): string => $this->text($b)));
        self::assertCount(0, $this->browser->request('GET', '/payments?state=paid')->filter('tr[data-charge]'));
        $this->browser->request('GET', '/payments/stand_in:ncs-5');
        self::assertResponseIsSuccessful();

        $this->em()->persist(new RecordedPayment('stand_in:ncs-5', 'NCS-0005', 'USD', 50000, new \DateTimeImmutable('2026-09-25'), MethodEnum::BankTransfer, 'Baraka Kimaro', new \DateTimeImmutable('2026-09-25')));
        $this->em()->flush();
        $page = $this->browser->request('GET', '/payments');
        self::assertSame(['All 3', 'Owed 2', 'Overdue 1', 'Paid 1'], $this->chips($page));
        self::assertSame(['NCS-0005 Northern Circuit Safari, voided', 'Lema party Tour booking', 'Thu 1 Oct 2026', 'USD 0.00', 'USD 500.00', 'USD 0.00', 'Paid'], $this->rows($page)[2]);
    }

    /** What is owed and how much of it is late stand in the dashboard's band. */
    public function testWhatIsOwedStandsInTheDashboardsBand(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertSame(['Owed to you USD 11,520.78 USD 3,912.20 overdue'], $this->figures($this->browser->request('GET', '/')));
    }

    /** Owed in several currencies, the late part is counted in charges. */
    public function testOwedInSeveralCurrenciesTheLateAreCounted(): void
    {
        StandInCharges::$besides = [new Charge('stand_in:tk-27', 'TK-0027', 'Ticket', 'DAR–ZNZ', null, 'Bahari party', 'TZS', 124000000, new \DateTimeImmutable('2026-09-20'), new \DateTimeImmutable('2026-09-30'))];
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertSame(['Owed to you TZS 1,240,000.00 · USD 11,520.78 2 overdue'], $this->figures($this->browser->request('GET', '/')));
    }

    /** The overdue card: the longest late first, five at most, each with what is owed and how late, and a door to the overdue. */
    public function testTheOverdueCardShowsTheLongestLateFirst(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $card = $this->card($this->browser->request('GET', '/'), 'Overdue');

        self::assertSame('Payments · the longest late first', $this->words($card->filter('.panel-head p')));
        self::assertSame(['NCS-0004 Kweka party USD 3,912.20 1 day late'], $card->filter('tbody tr')->each(fn (Crawler $row): string => $this->words($row)));
        self::assertSame('chip danger', $card->filter('.chip')->attr('class'));
        self::assertSame('1 of 1', $this->words($card->filter('.panel-foot .state')));
        self::assertSame('All payments', $this->words($card->filter('.panel-foot a.more')));
        self::assertSame('/payments?state=overdue', $card->filter('.panel-foot a.more')->attr('href'));

        self::mockTime('2026-11-20 09:00:00');
        $late = static fn (string $n, string $due, int $cents): Charge => new Charge('stand_in:ncs-'.$n, 'NCS-00'.$n, 'Tour booking', 'Northern Circuit Safari', null, 'Party '.$n, 'USD', $cents, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable($due));
        StandInCharges::$besides = [$late('11', '2026-11-19', 10000), $late('12', '2026-09-30', 20000), $late('13', '2026-10-20', 30000), $late('14', '2026-11-01', 40000)];
        $card = $this->card($this->browser->request('GET', '/'), 'Overdue');

        self::assertSame([
            'NCS-0012 Party 12 USD 200.00 51 days late',
            'NCS-0004 Kweka party USD 3,912.20 41 days late',
            'NCS-0013 Party 13 USD 300.00 31 days late',
            'NCS-0014 Party 14 USD 400.00 19 days late',
            'NCS-0002 Savanna Trails Travel USD 7,608.58 15 days late',
        ], $card->filter('tbody tr')->each(fn (Crawler $row): string => $this->words($row)));
        self::assertSame('5 of 6', $this->words($card->filter('.panel-foot .state')));
    }

    /** With nothing late the card says so and the band names no overdue; with nothing owed the band has no figure for it. */
    public function testNothingLateOrOwedIsSaidSo(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-4');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '3912.20', 'received' => '2026-10-11', 'method' => 'cash', 'reference' => '', 'note' => '']));

        $dashboard = $this->browser->request('GET', '/');
        self::assertSame(['Owed to you USD 7,608.58'], $this->figures($dashboard));
        $card = $this->card($dashboard, 'Overdue');
        self::assertCount(0, $card->filter('tbody tr'));
        self::assertSame('Nothing is overdue', $this->words($card->filter('[data-card-empty]')));
        self::assertSame('', $this->words($card->filter('.panel-foot .state')));

        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '7608.58', 'received' => '2026-10-11', 'method' => 'cash', 'reference' => '', 'note' => '']));
        self::assertSame([], $this->figures($this->browser->request('GET', '/')));
    }

    /** The figure and the card are for whoever reads payments. */
    public function testTheDashboardShowsPaymentsOnlyToWhoeverReadsThem(): void
    {
        $accounts = (new Department())->setName('Accounts')->setAllows(['payments.read']);
        $this->em()->persist($accounts);
        $this->em()->flush();

        $this->signedInAs($this->person('Daudi', TierEnum::Staff, ['dashboard.read', 'payments.read'], $accounts));
        $page = $this->browser->request('GET', '/');
        self::assertSame(['Owed to you USD 11,520.78 USD 3,912.20 overdue'], $this->figures($page));
        self::assertCount(1, $page->filter('[data-cards] .panel'));

        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['dashboard.read']));
        $page = $this->browser->request('GET', '/');
        self::assertSame([], $this->figures($page));
        self::assertCount(0, $page->filter('[data-cards] .panel'));
    }

    public function testAPaymentIsRecordedAgainstACharge(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        self::assertSame('NCS-0002', trim($page->filter('h1')->text()));
        self::assertSame('/', $page->selectLink('Northern Circuit Safari, 2 – 7 Aug 2027')->attr('href'), 'the record it comes from');
        self::assertSame(['Partner | Savanna Trails Travel', 'Pays | in 30 days', 'Raised | Tue 6 Oct 2026'], $this->facts($page, 'Owed by'));

        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '3,000.00', 'received' => '2026-10-10', 'method' => 'bank_transfer', 'reference' => 'CRDB 88213470', 'note' => '']));
        self::assertResponseRedirects('/payments/stand_in:ncs-2');
        $page = $this->browser->followRedirect();
        self::assertSame('Part paid', trim($page->filter('.title .chip')->text()));
        self::assertSame(['USD 7,608.58', 'USD 3,000.00', 'USD 4,608.58 due Thu 5 Nov 2026'], $this->band($page));
        self::assertSame([['Sat 10 Oct 2026', 'Bank transfer', 'CRDB 88213470', 'USD 3,000.00', 'Baraka Kimaro']], $page->filter('tr[data-payment]')->each(fn (Crawler $row): array => $row->filter('td')->each(fn (Crawler $cell): string => $this->text($cell))));
        self::assertSame('4608.58', $page->selectButton('Record the payment')->form()->getValues()['amount'], 'what is still owed, offered');

        $page = $this->browser->request('GET', '/payments');
        self::assertSame('USD 3,000.00', $this->rows($page)[1][4]);
        self::assertSame(['USD 8,520.78 2 charges', 'USD 3,912.20 1 charge', 'USD 3,000.00 1 payment'], $this->band($page));
    }

    public function testWhatIsTypedIsRefusedWithItsReason(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        foreach ([
            'amount' => [['amount' => '9,000.00'], 'More than is owed, USD 7,608.58: an overpayment is a credit, kept in the books.'],
            'amount ' => [['amount' => 'lots'], 'An amount to the cent: 3,000.00.'],
            'received' => [['received' => '2026-10-12'], 'A day up to today, as it was received.'],
            'method' => [['method' => 'barter'], 'Bank transfer, mobile money, cash, card or cheque.'],
        ] as $field => [$values, $reason]) {
            $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
            $form = $page->selectButton('Record the payment')->form();
            $page = $this->browser->request('POST', (string) $form->getUri(), [...['amount' => '100.00', 'received' => '2026-10-10', 'method' => 'cash', 'reference' => '', 'note' => '', '_token' => $form->getValues()['_token']], ...$values]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(trim($field), $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
            self::assertSame($reason, trim($page->filter('.field.wrong .hint')->text()), $field);
        }
        $this->browser->request('GET', '/payments/stand_in:nothing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPaymentStruckOutIsKeptAndNoLongerCounts(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-4');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '3912.20', 'received' => '2026-10-11', 'method' => 'mobile_money', 'reference' => 'MP 7H2K9QX', 'note' => '']));
        $page = $this->browser->followRedirect();
        self::assertSame('Paid', trim($page->filter('.title .chip')->text()));
        self::assertCount(0, $page->selectButton('Record the payment'), 'nothing is owed');

        $this->browser->submit($page->selectButton('Strike it out')->form(['reason' => 'Recorded on the wrong booking']));
        $page = $this->browser->followRedirect();
        self::assertSame('Overdue', trim($page->filter('.title .chip')->text()));
        self::assertSame('Struck out by Baraka Kimaro: Recorded on the wrong booking', $this->text($page->filter('tr[data-payment] .struck')));
        self::assertSame([], array_map(static fn (Payment $payment): string => $payment->key, $this->payments()->all()), 'a payment struck out is not one');
    }

    /** The books read the payments through the core, never from this module. */
    public function testPaymentsAreSaidToTheCore(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '3000', 'received' => '2026-10-10', 'method' => 'bank_transfer', 'reference' => 'CRDB 88213470', 'note' => '']));

        $payments = $this->payments()->against('stand_in:ncs-2');
        self::assertCount(1, $payments);
        self::assertSame(['USD', 300000, '2026-10-10', 'Bank transfer', 'CRDB 88213470'], [$payments[0]->currency, $payments[0]->amount, $payments[0]->receivedOn->format('Y-m-d'), $payments[0]->method, $payments[0]->reference]);
    }

    /** Reading what is owed, recording a payment and striking one out are three pairs, each a department must allow. */
    public function testPaymentsAreReadRecordedAndStruckOutWithTheirPairs(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        $this->browser->submit($page->selectButton('Record the payment')->form(['amount' => '100', 'received' => '2026-10-10', 'method' => 'cash', 'reference' => '', 'note' => '']));
        $accounts = (new Department())->setName('Accounts')->setAllows(['payments.read', 'payments.record', 'payments.manage']);
        $this->em()->persist($accounts);
        $this->em()->flush();
        $reader = $this->person('Daudi', TierEnum::Staff, ['payments.read'], $accounts);
        $recorder = $this->person('Neema', TierEnum::Staff, ['payments.read', 'payments.record'], $accounts);
        $outsider = $this->person('Elia', TierEnum::Staff, ['payments.read', 'payments.record', 'payments.manage']);

        $this->signedInAs($reader);
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->selectButton('Record the payment'));
        self::assertCount(0, $page->selectButton('Strike it out'));

        $this->signedInAs($recorder);
        $page = $this->browser->request('GET', '/payments/stand_in:ncs-2');
        self::assertCount(1, $page->selectButton('Record the payment'));
        self::assertCount(0, $page->selectButton('Strike it out'));

        $this->signedInAs($outsider);
        $this->browser->request('GET', '/payments');
        self::assertResponseStatusCodeSame(403, 'nobody outside a department reaches a module');
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output), $output->fetch());
    }

    private function payments(): PaymentsInterface
    {
        $payments = static::getContainer()->get(PaymentsInterface::class);
        self::assertInstanceOf(PaymentsInterface::class, $payments);

        return $payments;
    }

    /**
     * The band's figures, each with its note.
     *
     * @return list<string>
     */
    private function band(Crawler $page): array
    {
        return $page->filter('.band .f')->each(function (Crawler $field): string {
            $all = $this->text($field->filter('.v'));
            $note = $this->text($field->filter('.v em'));

            return trim(mb_substr($all, 0, mb_strlen($all) - mb_strlen($note)).' '.$note);
        });
    }

    /**
     * @return list<string>
     */
    private function chips(Crawler $page): array
    {
        return $page->filter('[data-facet="state"] a[data-chip]')->each(fn (Crawler $chip): string => $this->text($chip));
    }

    /**
     * Each charge's cells, a cell's small note after its text.
     *
     * @return list<list<string>>
     */
    private function rows(Crawler $page): array
    {
        return $page->filter('tr[data-charge]')->each(fn (Crawler $row): array => \array_slice($row->filter('td')->each(function (Crawler $cell): string {
            $all = $this->text($cell);
            $small = $this->text($cell->filter('small'));

            return trim(mb_substr($all, 0, mb_strlen($all) - mb_strlen($small)).' '.$small);
        }), 0, 7));
    }

    /**
     * @return list<string>
     */
    private function facts(Crawler $page, string $heading): array
    {
        $panel = $page->filter('section.panel')->reduce(static fn (Crawler $panel): bool => $heading === trim($panel->filter('.panel-head h2')->text('')));
        self::assertCount(1, $panel, $heading);

        return $panel->filter('.fact')->each(fn (Crawler $fact): string => $this->text($fact->filter('span')).' | '.$this->text($fact->filter('b')));
    }

    /**
     * @return list<string>
     */
    private function figures(Crawler $page): array
    {
        self::assertSame('organization', $page->filter('[data-dashboard]')->attr('data-dashboard'));

        return $page->filter('.band .f')->each(fn (Crawler $figure): string => $this->words($figure));
    }

    private function card(Crawler $page, string $title): Crawler
    {
        $card = $page->filter('[data-cards] .panel')->reduce(fn (Crawler $panel): bool => $title === $this->words($panel->filter('.panel-head h2')));
        self::assertCount(1, $card, $title);

        return $card;
    }

    /** The words of a node, each element's own apart, as a reader sees them. */
    private function words(Crawler $node): string
    {
        $words = [];
        foreach ($node as $element) {
            foreach ((new \DOMXPath($element->ownerDocument ?? new \DOMDocument()))->query('.//text()', $element) ?: [] as $text) {
                if ($text instanceof \DOMText) {
                    $words[] = trim((string) preg_replace('/\s+/u', ' ', $text->textContent));
                }
            }
        }

        return implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    private function text(Crawler $node): string
    {
        return 0 === $node->count() ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->text()));
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }
        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-tours.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function migrate(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());
    }
}
