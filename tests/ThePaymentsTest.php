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
use Vivutio\Contracts\Partner\Payment;
use Vivutio\Contracts\Partner\PaymentsInterface;

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

    public function testWhatIsOwedIsListedFromEveryModuleThatCharges(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertSame('/payments', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Payments')->attr('href'));
        $page = $this->browser->request('GET', '/payments');
        self::assertSame(['USD 11,520.78 2 charges', 'USD 3,912.20 1 charge', 'USD 0.00 no payments'], $this->band($page));
        self::assertSame(['All 2', 'Owed 2', 'Overdue 1', 'Paid 0'], $this->chips($page));
        self::assertSame([
            ['NCS-0004 Northern Circuit Safari, 18 – 23 Oct 2026', 'Kweka party Tour booking', 'Sat 10 Oct 2026', 'USD 3,912.20', 'USD 0.00', 'USD 3,912.20', 'Overdue'],
            ['NCS-0002 Northern Circuit Safari, 2 – 7 Aug 2027', 'Savanna Trails Travel Tour booking', 'Thu 5 Nov 2026', 'USD 7,608.58', 'USD 0.00', 'USD 7,608.58', 'Unpaid'],
        ], $this->rows($page));
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
