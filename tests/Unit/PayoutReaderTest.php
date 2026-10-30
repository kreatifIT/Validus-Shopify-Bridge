<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Shopify\PayoutReader;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class PayoutReaderTest extends TestCase
{
    protected function reader(): PayoutReader
    {
        return $this->app->make(PayoutReader::class);
    }

    /**
     * Answers the Payouts and BalanceTransactions queries with one page each,
     * or with the given pages in order.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $payoutPages
     * @param  array<int, array<int, array<string, mixed>>>  $transactionPages
     */
    protected function fakeShopify(array $payoutPages, array $transactionPages = [[]]): void
    {
        $responses = ['Payouts' => $payoutPages, 'BalanceTransactions' => $transactionPages];
        $page = ['Payouts' => 0, 'BalanceTransactions' => 0];

        Http::fake(function (Request $request) use ($responses, &$page) {
            $operation = str_contains($request['query'], 'query Payouts') ? 'Payouts' : 'BalanceTransactions';
            $connection = $operation === 'Payouts' ? 'payouts' : 'balanceTransactions';
            $index = $page[$operation]++;
            $hasNextPage = isset($responses[$operation][$index + 1]);

            return Http::response(['data' => ['shopifyPaymentsAccount' => [$connection => [
                'nodes' => $responses[$operation][$index] ?? [],
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? "cursor-{$index}" : null],
            ]]]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function payoutNode(int $id, string $issuedAt, string $net = '100.00'): array
    {
        return [
            'id' => "gid://shopify/ShopifyPaymentsPayout/{$id}",
            'legacyResourceId' => (string) $id,
            'issuedAt' => $issuedAt,
            'status' => 'PAID',
            'transactionType' => 'DEPOSIT',
            'net' => ['amount' => $net, 'currencyCode' => 'EUR'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transactionNode(int $id, int $payoutId, string $type = 'CHARGE', ?string $orderName = '#1001', string $amount = '50.00', string $fee = '1.20', string $net = '48.80'): array
    {
        return [
            'id' => "gid://shopify/ShopifyPaymentsBalanceTransaction/{$id}",
            'type' => $type,
            'transactionDate' => '2026-10-16T10:15:00Z',
            'adjustmentReason' => null,
            'amount' => ['amount' => $amount, 'currencyCode' => 'EUR'],
            'fee' => ['amount' => $fee],
            'net' => ['amount' => $net],
            'associatedOrder' => $orderName ? ['name' => $orderName] : null,
            'associatedPayout' => ['id' => "gid://shopify/ShopifyPaymentsPayout/{$payoutId}"],
        ];
    }

    protected function week(): array
    {
        return [
            CarbonImmutable::parse('2026-10-19', 'Europe/Rome'),
            CarbonImmutable::parse('2026-10-25', 'Europe/Rome'),
        ];
    }

    public function test_it_returns_the_payouts_of_the_range_oldest_first_with_their_transactions(): void
    {
        $this->fakeShopify(
            [[
                $this->payoutNode(2, '2026-10-22T08:00:00Z', '48.80'),
                $this->payoutNode(1, '2026-10-20T08:00:00Z', '-20.00'),
            ]],
            [[
                $this->transactionNode(10, 2),
                $this->transactionNode(11, 1, 'REFUND', '#0998', '-20.00', '0.00', '-20.00'),
            ]],
        );

        $payouts = $this->reader()->payoutsIssuedBetween(...$this->week());

        $this->assertSame(['1', '2'], array_column($payouts, 'legacyId'));

        $this->assertSame('48.80', $payouts[1]['net']);
        $this->assertSame('EUR', $payouts[1]['currency']);
        $this->assertSame('PAID', $payouts[1]['status']);
        $this->assertSame('DEPOSIT', $payouts[1]['transactionType']);
        $this->assertSame('2026-10-22 10:00', $payouts[1]['issuedAt']->format('Y-m-d H:i'));

        $this->assertSame([[
            'id' => 'gid://shopify/ShopifyPaymentsBalanceTransaction/10',
            'type' => 'CHARGE',
            'orderName' => '#1001',
            'amount' => '50.00',
            'fee' => '1.20',
            'net' => '48.80',
            'currency' => 'EUR',
            'adjustmentReason' => null,
        ]], array_map(fn (array $transaction) => array_diff_key($transaction, ['date' => true]), $payouts[1]['transactions']));
        $this->assertSame('2026-10-16 12:15', $payouts[1]['transactions'][0]['date']->format('Y-m-d H:i'));

        $this->assertSame(['REFUND'], array_column($payouts[0]['transactions'], 'type'));
    }

    /** Shopify filters by day in the shop's timezone, so the exact range is applied in the range's timezone. */
    public function test_it_keeps_exactly_the_days_of_the_range_in_its_timezone(): void
    {
        $this->fakeShopify([[
            $this->payoutNode(1, '2026-10-18T21:30:00Z'), // Sunday 23:30 in Rome
            $this->payoutNode(2, '2026-10-18T22:30:00Z'), // Monday 00:30 in Rome
            $this->payoutNode(3, '2026-10-25T21:30:00Z'), // Sunday 22:30 in Rome, back on CET
            $this->payoutNode(4, '2026-10-25T23:30:00Z'), // Monday 00:30 in Rome
        ]]);

        $payouts = $this->reader()->payoutsIssuedBetween(...$this->week());

        $this->assertSame(['2', '3'], array_column($payouts, 'legacyId'));
    }

    public function test_it_asks_shopify_for_a_day_more_on_either_side_and_leaves_out_the_transfers(): void
    {
        $this->fakeShopify([[$this->payoutNode(1, '2026-10-20T08:00:00Z')]]);

        $this->reader()->payoutsIssuedBetween(...$this->week());

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'query Payouts')
            && $request['variables']['q'] === 'issued_at:>=2026-10-18 issued_at:<=2026-10-26');

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'query BalanceTransactions')
            && str_contains($request['query'], 'hideTransfers: true')
            && $request['variables']['q'] === 'payout_date:>=2026-10-18 payout_date:<=2026-10-26');
    }

    public function test_it_follows_the_pages_of_both_connections(): void
    {
        $this->fakeShopify(
            [[$this->payoutNode(1, '2026-10-20T08:00:00Z')], [$this->payoutNode(2, '2026-10-21T08:00:00Z')]],
            [[$this->transactionNode(10, 1)], [$this->transactionNode(11, 2)], [$this->transactionNode(12, 2)]],
        );

        $payouts = $this->reader()->payoutsIssuedBetween(...$this->week());

        $this->assertSame([1, 2], array_map(fn (array $payout) => count($payout['transactions']), $payouts));

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'query BalanceTransactions')
            && $request['variables']['after'] === 'cursor-1');
        Http::assertSentCount(5);
    }

    public function test_transactions_of_payouts_outside_the_range_are_left_out(): void
    {
        $this->fakeShopify(
            [[$this->payoutNode(1, '2026-10-20T08:00:00Z')]],
            [[$this->transactionNode(10, 1), $this->transactionNode(11, 99)]],
        );

        $payouts = $this->reader()->payoutsIssuedBetween(...$this->week());

        $this->assertSame(
            ['gid://shopify/ShopifyPaymentsBalanceTransaction/10'],
            array_column($payouts[0]['transactions'], 'id'),
        );
    }

    public function test_a_payout_without_transactions_has_an_empty_list(): void
    {
        $this->fakeShopify([[$this->payoutNode(1, '2026-10-20T08:00:00Z')]]);

        $payouts = $this->reader()->payoutsIssuedBetween(...$this->week());

        $this->assertSame([], $payouts[0]['transactions']);
    }

    public function test_without_payouts_it_does_not_ask_for_transactions(): void
    {
        $this->fakeShopify([[]]);

        $this->assertSame([], $this->reader()->payoutsIssuedBetween(...$this->week()));

        Http::assertSentCount(1);
    }
}
