<?php

namespace justinholtweb\vismaz\helpers;

use DateTimeInterface;

/**
 * Writer for SIE 4, the Swedish standard accounting interchange format.
 *
 * This exists because a large share of Swedish merchants run *Visma Administration*, a desktop
 * product with no public API. They cannot be pushed to, but every one of them can import a `.se`
 * file, and it is the same journal Vismaz would otherwise have posted as a voucher.
 *
 * Two things about the format are non-negotiable and both are easy to get wrong:
 *
 *  1. **The file is CP437, not UTF-8.** `#FORMAT PC8` is a promise about the bytes. Å, Ä and Ö
 *     handed over as UTF-8 make an importer either reject the file or silently mangle every
 *     account name in the chart.
 *  2. **Lines end CRLF**, and a field containing a space, a quote or a brace must be quoted.
 */
class Sie4
{
    private array $lines = [];
    private array $accounts = [];
    private int $verificationNumber = 1;

    public function __construct(
        private readonly string $companyName,
        private readonly ?string $organisationNumber,
        private readonly DateTimeInterface $financialYearStart,
        private readonly DateTimeInterface $financialYearEnd,
        private readonly string $generatorName = 'Vismaz',
        private readonly string $generatorVersion = '5.0.0',
    ) {
    }

    /**
     * Emit the file header. Order matters to strict importers: identification before the chart,
     * chart before the verifications.
     */
    public function header(?DateTimeInterface $generatedAt = null): self
    {
        $this->lines[] = '#FLAGGA 0';
        $this->lines[] = '#PROGRAM ' . $this->quote($this->generatorName) . ' ' . $this->quote($this->generatorVersion);
        $this->lines[] = '#FORMAT PC8';
        $this->lines[] = '#GEN ' . ($generatedAt ?? new \DateTimeImmutable())->format('Ymd');
        $this->lines[] = '#SIETYP 4';
        $this->lines[] = '#FNAMN ' . $this->quote($this->companyName);

        if ($this->organisationNumber) {
            $this->lines[] = '#ORGNR ' . $this->quote($this->organisationNumber);
        }

        $this->lines[] = sprintf(
            '#RAR 0 %s %s',
            $this->financialYearStart->format('Ymd'),
            $this->financialYearEnd->format('Ymd')
        );

        return $this;
    }

    /**
     * Declare an account. Repeats are collapsed — an importer given the same #KONTO twice may
     * either warn or take the second name, and neither is what the merchant wants.
     */
    public function account(string $number, ?string $name = null): self
    {
        if (isset($this->accounts[$number])) {
            return $this;
        }

        $this->accounts[$number] = $name ?? Bas::label($number) ?? $number;

        return $this;
    }

    /**
     * A verification (#VER) and its transactions.
     *
     * @param array<array{account: string, amount: float, text?: ?string, dimension?: ?string}> $transactions
     */
    public function verification(
        DateTimeInterface $date,
        string $text,
        array $transactions,
        string $series = 'A',
        ?int $number = null,
    ): self {
        $amounts = array_map(static fn(array $t): float => (float)$t['amount'], $transactions);

        if (!Money::balances($amounts)) {
            throw new \RuntimeException(sprintf(
                'Refusing to write an unbalanced verification "%s": debits and credits differ by %s.',
                $text,
                Money::format(Money::sum($amounts))
            ));
        }

        $this->lines[] = sprintf(
            '#VER %s %s %s %s',
            $this->quote($series),
            $this->quote((string)($number ?? $this->verificationNumber++)),
            $date->format('Ymd'),
            $this->quote($text)
        );
        $this->lines[] = '{';

        foreach ($transactions as $transaction) {
            $this->account($transaction['account']);

            $this->lines[] = sprintf(
                '#TRANS %s {%s} %s%s',
                $transaction['account'],
                $transaction['dimension'] ?? '',
                number_format((float)$transaction['amount'], 2, '.', ''),
                isset($transaction['text']) && $transaction['text'] !== null
                    ? ' ' . $date->format('Ymd') . ' ' . $this->quote($transaction['text'])
                    : ''
            );
        }

        $this->lines[] = '}';

        return $this;
    }

    /**
     * The finished file, encoded CP437 as `#FORMAT PC8` promises.
     *
     * The account declarations are spliced in ahead of the verifications rather than appended,
     * because they were discovered while writing them.
     */
    public function toString(): string
    {
        $chart = [];
        ksort($this->accounts, SORT_STRING);

        foreach ($this->accounts as $number => $name) {
            $chart[] = '#KONTO ' . $number . ' ' . $this->quote($name);
        }

        $headerLength = 0;

        foreach ($this->lines as $index => $line) {
            if (str_starts_with($line, '#RAR')) {
                $headerLength = $index + 1;
                break;
            }
        }

        $all = array_merge(
            array_slice($this->lines, 0, $headerLength),
            $chart,
            array_slice($this->lines, $headerLength)
        );

        return self::toCp437(implode("\r\n", $all) . "\r\n");
    }

    /**
     * UTF-8 to CP437, transliterating anything the code page cannot hold rather than dropping it.
     */
    public static function toCp437(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'CP437//TRANSLIT', $utf8);

        return $converted === false ? $utf8 : $converted;
    }

    /**
     * Quote a field. SIE escapes an embedded quote with a backslash.
     */
    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
