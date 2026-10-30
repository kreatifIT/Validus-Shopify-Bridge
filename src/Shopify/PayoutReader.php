<?php

namespace Kreatif\ValidusShopifyBridge\Shopify;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * Reads Shopify Payments payouts - what Shopify transferred to the shop's bank
 * account, and which charges, refunds and fees each transfer is made of - so a
 * consuming app can report them (e.g. a weekly mail to the bookkeeping).
 *
 * Needs the read_shopify_payments_accounts and read_shopify_payments_payouts
 * access scopes. Only covers Shopify Payments: payments through any other
 * gateway (PayPal, bank transfer, ...) never show up in a payout.
 *
 * Amounts are returned as Shopify's decimal strings, unrounded and unformatted.
 */
class PayoutReader
{
    public function __construct(protected ShopifyGraphqlClient $client) {}

    /**
     * Payouts issued from $from to $to (both days included, in the timezone
     * of $from and $to), oldest first, each with its balance transactions.
     *
     * Shopify's search syntax filters by date only and in the shop's own
     * timezone, so both queries ask for a day more on either side and the
     * exact range is applied here. The balance transactions have no payout
     * filter Shopify documents as such: they're fetched by payout date and
     * matched to their payout through associatedPayout.
     *
     * @return array<int, array{id: string, legacyId: string, issuedAt: CarbonImmutable, status: string, transactionType: string, net: string, currency: string, transactions: array<int, array{id: string, type: string, date: CarbonImmutable, orderName: ?string, amount: string, fee: string, net: string, currency: string, adjustmentReason: ?string}>}>
     */
    public function payoutsIssuedBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->endOfDay();
        $dateFilter = fn (string $field) => sprintf(
            '%1$s:>=%2$s %1$s:<=%3$s',
            $field,
            $from->subDay()->toDateString(),
            $to->addDay()->toDateString(),
        );

        $timezone = $from->getTimezone();

        $payouts = collect($this->payouts($dateFilter('issued_at')))
            ->map(fn (array $node) => $this->payout($node, $timezone))
            ->filter(fn (array $payout) => $payout['issuedAt']->between($from, $to))
            ->sortBy(fn (array $payout) => $payout['issuedAt']->getTimestamp())
            ->values();

        if ($payouts->isEmpty()) {
            return [];
        }

        $transactions = collect($this->balanceTransactions($dateFilter('payout_date')))
            ->groupBy(fn (array $node) => (string) Arr::get($node, 'associatedPayout.id'));

        return $payouts
            ->map(fn (array $payout) => [
                ...$payout,
                'transactions' => $transactions->get($payout['id'], collect())
                    ->map(fn (array $node) => $this->transaction($node, $timezone))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function payouts(string $search): array
    {
        $query = <<<'QUERY'
            query Payouts($q: String!, $after: String) {
              shopifyPaymentsAccount {
                payouts(first: 50, after: $after, query: $q, sortKey: ISSUED_AT) {
                  nodes {
                    id
                    legacyResourceId
                    issuedAt
                    status
                    transactionType
                    net {
                      amount
                      currencyCode
                    }
                  }
                  pageInfo {
                    hasNextPage
                    endCursor
                  }
                }
              }
            }
            QUERY;

        return $this->paginate($query, $search, 'shopifyPaymentsAccount.payouts');
    }

    /**
     * Without the transfers: those are the payouts themselves again.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function balanceTransactions(string $search): array
    {
        $query = <<<'QUERY'
            query BalanceTransactions($q: String!, $after: String) {
              shopifyPaymentsAccount {
                balanceTransactions(first: 100, after: $after, query: $q, hideTransfers: true, sortKey: PROCESSED_AT) {
                  nodes {
                    id
                    type
                    transactionDate
                    adjustmentReason
                    amount {
                      amount
                      currencyCode
                    }
                    fee {
                      amount
                    }
                    net {
                      amount
                    }
                    associatedOrder {
                      name
                    }
                    associatedPayout {
                      id
                    }
                  }
                  pageInfo {
                    hasNextPage
                    endCursor
                  }
                }
              }
            }
            QUERY;

        return $this->paginate($query, $search, 'shopifyPaymentsAccount.balanceTransactions');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function paginate(string $query, string $search, string $connection): array
    {
        $nodes = [];
        $after = null;

        do {
            $data = $this->client->query($query, ['q' => $search, 'after' => $after]);

            $nodes = [...$nodes, ...Arr::get($data, "{$connection}.nodes", [])];
            $after = Arr::get($data, "{$connection}.pageInfo.endCursor");
        } while (Arr::get($data, "{$connection}.pageInfo.hasNextPage") && $after);

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, legacyId: string, issuedAt: CarbonImmutable, status: string, transactionType: string, net: string, currency: string}
     */
    protected function payout(array $node, \DateTimeZone $timezone): array
    {
        return [
            'id' => $node['id'],
            'legacyId' => (string) $node['legacyResourceId'],
            'issuedAt' => CarbonImmutable::parse($node['issuedAt'])->setTimezone($timezone),
            'status' => $node['status'],
            'transactionType' => $node['transactionType'],
            'net' => Arr::get($node, 'net.amount', '0'),
            'currency' => Arr::get($node, 'net.currencyCode', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, type: string, date: CarbonImmutable, orderName: ?string, amount: string, fee: string, net: string, currency: string, adjustmentReason: ?string}
     */
    protected function transaction(array $node, \DateTimeZone $timezone): array
    {
        return [
            'id' => $node['id'],
            'type' => $node['type'],
            'date' => CarbonImmutable::parse($node['transactionDate'])->setTimezone($timezone),
            'orderName' => Arr::get($node, 'associatedOrder.name'),
            'amount' => Arr::get($node, 'amount.amount', '0'),
            'fee' => Arr::get($node, 'fee.amount', '0'),
            'net' => Arr::get($node, 'net.amount', '0'),
            'currency' => Arr::get($node, 'amount.currencyCode', ''),
            'adjustmentReason' => $node['adjustmentReason'] ?? null,
        ];
    }
}
