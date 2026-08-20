<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;
use justinholtweb\vismaz\helpers\Money;

/**
 * One posting in a journal entry: an account, and a signed amount.
 *
 * Debit-positive throughout. Visma's voucher API takes separate `DebitAmount` and `CreditAmount`
 * fields, and SIE takes a single signed `#TRANS`, so the internal form is the signed one and the
 * split happens at the edge.
 */
class VoucherLine extends Model
{
    public string $account = '';

    /** Signed amount: positive debits, negative credits. */
    public float $amount = 0.0;

    public ?string $text = null;

    /** Visma VAT code id, when the account is VAT-coded. */
    public ?string $vatCodeId = null;

    /** Cost centre / project dimension, for SIE `{}`. */
    public ?string $dimension = null;

    public function getDebit(): float
    {
        return $this->amount > 0 ? Money::round($this->amount) : 0.0;
    }

    public function getCredit(): float
    {
        return $this->amount < 0 ? Money::round(-$this->amount) : 0.0;
    }

    /**
     * Visma's `VoucherRow` shape.
     */
    public function toVismaRow(): array
    {
        $row = [
            'AccountNumber' => (int)$this->account,
            'DebitAmount' => $this->getDebit(),
            'CreditAmount' => $this->getCredit(),
        ];

        if ($this->text !== null) {
            $row['TransactionText'] = $this->text;
        }

        if ($this->vatCodeId !== null) {
            $row['VatCodeId'] = $this->vatCodeId;
        }

        return $row;
    }

    /**
     * SIE `#TRANS` shape.
     */
    public function toSieTransaction(): array
    {
        return [
            'account' => $this->account,
            'amount' => Money::round($this->amount),
            'text' => $this->text,
            'dimension' => $this->dimension,
        ];
    }
}
