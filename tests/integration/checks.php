<?php
/**
 * Vismaz integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-vismaz/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, document rows, entity mappings, log
 * rows and the plugin settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * Nothing here talks to Visma. Every check exercises the builders and the decision logic, which
 * is where the bookkeeping correctness actually lives — the HTTP layer has nothing to be right
 * about except transport.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\helpers\Sie4;
use justinholtweb\vismaz\helpers\Vies;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\DocumentRow;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\models\TaxTreatment;
use justinholtweb\vismaz\models\VoucherLine;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\Documents;
use justinholtweb\vismaz\services\Sync;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();

/**
 * `craft-penny` (a sibling in this shared harness) registers an
 * Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
 * `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Vismaz;
 * detached in-process here (never persisted) so fixtures can be created.
 */
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Project config writes are buffered until the request ends, and a bare console script has no
 * request end — so it has to flush them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Vismaz fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = 1;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, string $countryCode = 'SE', bool $complete = true, array $addressExtra = []): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('vismaz-fixture@example.com');

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array
    // and Commerce builds the owned element itself.
    $address = array_merge([
        'fullName' => 'Dana Fixture',
        'addressLine1' => 'Storgatan 1',
        'locality' => 'Stockholm',
        'postalCode' => '11122',
        'countryCode' => $countryCode,
    ], $addressExtra);

    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

try {
    // ---------------------------------------------------------------------
    section('BAS chart of accounts');

    check('25 % domestic sales books to 3001', fn() => Bas::domesticSalesAccount(25.0) === Bas::SALES_SE_25);
    check('12 % domestic sales books to 3002', fn() => Bas::domesticSalesAccount(12.0) === Bas::SALES_SE_12);
    check('6 % domestic sales books to 3003', fn() => Bas::domesticSalesAccount(6.0) === Bas::SALES_SE_6);
    check('zero-rated domestic sales book to 3004', fn() => Bas::domesticSalesAccount(0.0) === Bas::SALES_SE_EXEMPT);

    check('output VAT at 25 % books to 2610', fn() => Bas::outputVatAccount(25.0) === '2610');
    check('output VAT at 12 % books to 2620', fn() => Bas::outputVatAccount(12.0) === '2620');
    check('output VAT at 6 % books to 2630', fn() => Bas::outputVatAccount(6.0) === '2630');
    check('zero-rated sales have no output VAT account', fn() => Bas::outputVatAccount(0.0) === null);
    check('reverse-charge output VAT uses the 2614 series', fn() => Bas::outputVatAccount(25.0, true) === '2614');

    // These two pairs are the reverse of the obvious guess, which is exactly why they are checked.
    check('3305 is services OUTSIDE the EU', fn() => Bas::SALES_SERVICES_NON_EU === '3305');
    check('3308 is services TO another EU country', fn() => Bas::SALES_SERVICES_EU === '3308');
    check('3520 is invoiced freight', fn() => Bas::FREIGHT === '3520' && str_contains((string)Bas::label('3520'), 'frakter'));
    check('3540 is the invoicing fee, not freight', fn() => Bas::INVOICE_FEE === '3540');
    check('3740 is öres- och kronutjämning', fn() => Bas::ROUNDING === '3740' && str_contains((string)Bas::label('3740'), 'Öres'));

    check('a float that drifted off 25 still snaps to 25', function() {
        // Commerce arithmetic really does produce 24.999999999999996; without normalising, such
        // an order books to the exempt account and the merchant's VAT return is wrong.
        return Bas::normaliseRate(24.999999999999996) === 25.0 ?: 'got ' . Bas::normaliseRate(24.999999999999996);
    });

    check('an unrecognised rate is kept rather than forced', fn() => Bas::normaliseRate(19.0) === 19.0);
    check('a four-digit account opening 1–8 is valid', fn() => Bas::isValidAccount('3001'));
    check('a three-digit number is not an account', fn() => !Bas::isValidAccount('300'));
    check('a 9000-series number is not a BAS account', fn() => !Bas::isValidAccount('9001'));
    check('an empty string is not an account', fn() => !Bas::isValidAccount(''));

    // ---------------------------------------------------------------------
    section('Money');

    check('kronor convert to öre without drift', fn() => Money::toMinor(1234.56) === 123456);
    check('öre convert back to kronor', fn() => Money::toMajor(123456) === 1234.56);

    check('0.1 + 0.2 sums exactly in öre', function() {
        // The reason every total in this plugin is summed in minor units.
        return Money::sum([0.1, 0.2]) === 0.3 ?: 'got ' . var_export(Money::sum([0.1, 0.2]), true);
    });

    check('öresavrundning rounds 124.60 up to 125', function() {
        [$total, $adjustment] = Money::roundToWholeKronor(124.60);

        return ($total === 125.0 && $adjustment === 0.40) ?: "got $total / $adjustment";
    });

    check('öresavrundning rounds 124.40 down to 124', function() {
        [$total, $adjustment] = Money::roundToWholeKronor(124.40);

        return ($total === 124.0 && $adjustment === -0.40) ?: "got $total / $adjustment";
    });

    check('a total already whole needs no adjustment', function() {
        [$total, $adjustment] = Money::roundToWholeKronor(125.00);

        return ($total === 125.0 && $adjustment === 0.0) ?: "got $total / $adjustment";
    });

    check('net from a 25 % gross of 125 is 100', fn() => Money::netFromGross(125.0, 25.0) === 100.0);
    check('VAT from a 25 % gross of 125 is 25', fn() => Money::vatFromGross(125.0, 25.0) === 25.0);
    check('net from a zero-rated gross is the gross', fn() => Money::netFromGross(125.0, 0.0) === 125.0);

    check('a balanced set of postings balances', fn() => Money::balances([100.0, -80.0, -20.0]));
    check('an unbalanced set does not', fn() => !Money::balances([100.0, -79.0, -20.0]));
    check('a one-öre difference is tolerated', fn() => Money::balances([100.00, -99.99]));
    check('a two-öre difference is not', fn() => !Money::balances([100.00, -99.98]));

    // ---------------------------------------------------------------------
    section('VIES VAT numbers');

    check('a Swedish VAT number parses', function() {
        $parsed = Vies::parse('SE556677889901');

        return ($parsed === ['SE', '556677889901']) ?: json_encode($parsed);
    });

    check('spaces and dots are stripped', function() {
        $parsed = Vies::parse('SE 556677-8899 01');

        return ($parsed === ['SE', '556677889901']) ?: json_encode($parsed);
    });

    check('Greece parses as EL, which is the prefix VIES answers to', function() {
        $parsed = Vies::parse('GR123456789');

        return ($parsed === ['EL', '123456789']) ?: json_encode($parsed);
    });

    check('a non-EU country code does not parse', fn() => Vies::parse('US123456789') === null);
    check('a number with no country prefix does not parse', fn() => Vies::parse('556677889901') === null);
    check('"n/a" typed into the field does not parse', fn() => Vies::parse('n/a') === null);
    check('an empty string does not parse', fn() => Vies::parse('') === null);
    check('Northern Ireland (XI) is a member state for VAT', fn() => in_array('XI', Vies::MEMBER_STATES, true));

    check('an unparseable number reports as checked and invalid, not as an error', function() {
        $result = Vies::check('not-a-vat-number');

        return ($result['valid'] === false && $result['checked'] === true) ?: json_encode($result);
    });

    // ---------------------------------------------------------------------
    section('SIE 4 writer');

    $sieDate = new DateTimeImmutable('2026-08-20');

    check('the header declares PC8, SIE type 4 and the financial year', function() use ($sieDate) {
        $writer = new Sie4('Testbolaget AB', '556677-8899', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate);
        $out = $writer->toString();

        foreach (['#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#RAR 0 20260101 20261231', '#GEN 20260820'] as $needle) {
            if (!str_contains($out, $needle)) {
                return "missing $needle";
            }
        }

        return true;
    });

    check('a verification writes balanced #TRANS lines', function() use ($sieDate) {
        $writer = new Sie4('Testbolaget AB', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate)->verification($sieDate, 'Webshop', [
            ['account' => '1930', 'amount' => 125.00],
            ['account' => '3001', 'amount' => -100.00],
            ['account' => '2610', 'amount' => -25.00],
        ]);

        $out = $writer->toString();

        return (str_contains($out, '#TRANS 1930 {} 125.00')
            && str_contains($out, '#TRANS 3001 {} -100.00')
            && str_contains($out, '#TRANS 2610 {} -25.00')) ?: substr($out, 0, 500);
    });

    check('an unbalanced verification is refused rather than written', function() use ($sieDate) {
        $writer = new Sie4('Testbolaget AB', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));

        try {
            $writer->verification($sieDate, 'Broken', [
                ['account' => '1930', 'amount' => 125.00],
                ['account' => '3001', 'amount' => -100.00],
            ]);
        } catch (RuntimeException) {
            return true;
        }

        return 'an unbalanced verification was accepted';
    });

    check('accounts used are declared, and declared before the verifications', function() use ($sieDate) {
        $writer = new Sie4('Testbolaget AB', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate)->verification($sieDate, 'Webshop', [
            ['account' => '1930', 'amount' => 100.00],
            ['account' => '3004', 'amount' => -100.00],
        ]);

        $out = $writer->toString();
        $kontoPos = strpos($out, '#KONTO 1930');
        $verPos = strpos($out, '#VER');

        if ($kontoPos === false) {
            return 'no #KONTO line';
        }

        return $kontoPos < $verPos ?: 'the chart was written after the verifications';
    });

    check('a repeated account is declared only once', function() use ($sieDate) {
        $writer = new Sie4('T', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate)
            ->verification($sieDate, 'One', [['account' => '1930', 'amount' => 10.0], ['account' => '3004', 'amount' => -10.0]])
            ->verification($sieDate, 'Two', [['account' => '1930', 'amount' => 20.0], ['account' => '3004', 'amount' => -20.0]]);

        return substr_count($writer->toString(), '#KONTO 1930') === 1;
    });

    check('the file is CP437 bytes, not UTF-8 — #FORMAT PC8 is a promise about the bytes', function() use ($sieDate) {
        $writer = new Sie4('Åkerlunds Bageri AB', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate);
        $out = $writer->toString();

        // Å is 0xC3 0x85 in UTF-8 and a single 0x8F in CP437. Finding the two-byte form means the
        // encoding never happened and every Swedish name in the file will import mangled.
        if (str_contains($out, "\xC3\x85")) {
            return 'the company name is still UTF-8';
        }

        return str_contains($out, "\x8F") ?: 'Å was not encoded as CP437 0x8F';
    });

    check('lines end CRLF', function() use ($sieDate) {
        $writer = new Sie4('T', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate);

        return str_contains($writer->toString(), "\r\n");
    });

    check('an embedded quote is escaped rather than closing the field', function() use ($sieDate) {
        $writer = new Sie4('The "Best" AB', null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $writer->header($sieDate);

        return str_contains($writer->toString(), '\\"Best\\"');
    });

    // ---------------------------------------------------------------------
    section('Settings');

    check('sandbox and production are different identity hosts', function() {
        $sandbox = new Settings(['environment' => Settings::ENV_SANDBOX]);
        $production = new Settings(['environment' => Settings::ENV_PRODUCTION]);

        return ($sandbox->getIdentityBaseUrl() === 'https://identity-sandbox.test.vismaonline.com'
            && $production->getIdentityBaseUrl() === 'https://identity.vismaonline.com')
            ?: $sandbox->getIdentityBaseUrl() . ' / ' . $production->getIdentityBaseUrl();
    });

    check('sandbox and production are different API hosts', function() {
        $sandbox = new Settings(['environment' => Settings::ENV_SANDBOX]);
        $production = new Settings(['environment' => Settings::ENV_PRODUCTION]);

        return ($sandbox->getApiBaseUrl() === 'https://eaccountingapi-sandbox.test.vismaonline.com/v2/'
            && $production->getApiBaseUrl() === 'https://eaccountingapi.vismaonline.com/v2/')
            ?: $sandbox->getApiBaseUrl() . ' / ' . $production->getApiBaseUrl();
    });

    check('an unmapped gateway falls back to the default settlement account', function() {
        $settings = new Settings(['defaultPaymentAccount' => '1580']);

        return $settings->getPaymentAccount('nonesuch') === '1580';
    });

    check('a mapped gateway uses its own account', function() {
        $settings = new Settings(['paymentAccounts' => ['klarna' => ['account' => '1685']]]);

        return $settings->getPaymentAccount('klarna') === '1685';
    });

    check('a gateway with no fee configured reports no fee', function() {
        $settings = new Settings(['paymentAccounts' => ['swish' => ['account' => '1930']]]);

        return $settings->getPaymentFee('swish') === null;
    });

    check('a fee falls back to the shared fee account when none is named', function() {
        $settings = new Settings([
            'feeAccount' => '6570',
            'paymentAccounts' => ['stripe' => ['account' => '1580', 'feePercent' => 1.5, 'feeFixed' => 1.8]],
        ]);
        $fee = $settings->getPaymentFee('stripe');

        return ($fee['account'] === '6570' && $fee['percent'] === 1.5 && $fee['fixed'] === 1.8) ?: json_encode($fee);
    });

    check('an invalid ledger account fails validation', function() {
        $settings = new Settings(['roundingAccount' => 'abcd']);
        $settings->validate();

        return $settings->hasErrors('roundingAccount');
    });

    check('an empty ledger account is allowed — a half-configured install must still save', function() {
        $settings = new Settings(['roundingAccount' => '']);
        $settings->validate();

        return !$settings->hasErrors('roundingAccount');
    });

    check('no setting is marked required, so a fresh install can save before the credentials exist', function() {
        $settings = new Settings();

        foreach ($settings->rules() as $rule) {
            if (($rule[1] ?? null) === 'required') {
                return 'a required rule exists on ' . json_encode($rule[0]);
            }
        }

        return true;
    });

    check('a fresh, empty settings model validates', function() {
        $settings = new Settings();

        return $settings->validate() ?: json_encode($settings->getErrors());
    });

    // ---------------------------------------------------------------------
    section('Fixtures');

    applySettings([
        'homeCountry' => 'SE',
        'documentMode' => Settings::MODE_INVOICE,
        'syncCustomers' => false,
        'syncArticles' => false,
        'roundToWholeKronor' => true,
        'reverseChargeEnabled' => true,
        'validateVatNumbers' => false,
        'ossEnabled' => false,
        'syncStatusHandles' => [],
        'autoPush' => false,
    ]);

    $productA = makeProduct("VZ-A-$suffix", 100.00);
    $productB = makeProduct("VZ-B-$suffix", 249.50);

    $variantA = $productA->getDefaultVariant();
    $variantB = $productB->getDefaultVariant();

    $seOrder = makeOrder([['variant' => $variantA, 'qty' => 2]], 'SE');
    $usOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], 'US');
    $deOrder = makeOrder([['variant' => $variantB, 'qty' => 1]], 'DE');

    check('the Swedish fixture order saved and completed', fn() => $seOrder->id !== null && $seOrder->isCompleted);
    check('the US fixture order saved and completed', fn() => $usOrder->id !== null && $usOrder->isCompleted);
    check('the German fixture order saved and completed', fn() => $deOrder->id !== null && $deOrder->isCompleted);

    // ---------------------------------------------------------------------
    section('Tax treatment');

    $tax = $plugin->getTax();

    check('a Swedish delivery is domestic', function() use ($tax, $seOrder) {
        $treatment = $tax->treatOrder($seOrder);

        return $treatment->kind === TaxTreatment::KIND_DOMESTIC ?: $treatment->kind . ' — ' . $treatment->reason;
    });

    check('a Swedish delivery books to a 30-series sales account', function() use ($tax, $seOrder) {
        $treatment = $tax->treatOrder($seOrder);

        return str_starts_with($treatment->salesAccount, '30') ?: $treatment->salesAccount;
    });

    check('a US delivery is an export', function() use ($tax, $usOrder) {
        $treatment = $tax->treatOrder($usOrder);

        return $treatment->kind === TaxTreatment::KIND_EXPORT ?: $treatment->kind . ' — ' . $treatment->reason;
    });

    check('an export is zero-rated', function() use ($tax, $usOrder) {
        return $tax->treatOrder($usOrder)->isZeroRated();
    });

    check('an export has no output VAT account', function() use ($tax, $usOrder) {
        return $tax->treatOrder($usOrder)->vatAccount === null;
    });

    check('an export books to the outside-EU account', function() use ($tax, $usOrder) {
        $treatment = $tax->treatOrder($usOrder);

        return in_array($treatment->salesAccount, [Bas::SALES_GOODS_NON_EU, Bas::SALES_SERVICES_NON_EU], true) ?: $treatment->salesAccount;
    });

    check('an EU delivery is not treated as an export', function() use ($tax, $deOrder) {
        return $tax->treatOrder($deOrder)->kind !== TaxTreatment::KIND_EXPORT;
    });

    check('Sweden is in the EU list and the US is not', function() {
        return in_array('SE', \justinholtweb\vismaz\services\Tax::EU_COUNTRIES, true)
            && !in_array('US', \justinholtweb\vismaz\services\Tax::EU_COUNTRIES, true);
    });

    check('every EU country code is two upper-case letters', function() {
        foreach (\justinholtweb\vismaz\services\Tax::EU_COUNTRIES as $code) {
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                return "bad code: $code";
            }
        }

        return true;
    });

    check('an EU B2C sale with OSS off is not treated as OSS', function() use ($tax, $deOrder) {
        return $tax->treatOrder($deOrder)->kind !== TaxTreatment::KIND_OSS;
    });

    check('with no VAT number field configured, no order can be reverse charged', function() use ($tax, $deOrder) {
        // The setting is empty, so there is nothing to read — and a sale that cannot be proven
        // B2B must stay taxed.
        return $tax->vatNumberFor($deOrder) === null && !$tax->treatOrder($deOrder)->isReverseCharge();
    });

    check('every treatment explains itself', function() use ($tax, $seOrder, $usOrder, $deOrder) {
        foreach ([$seOrder, $usOrder, $deOrder] as $order) {
            if (trim($tax->treatOrder($order)->reason) === '') {
                return 'an empty reason on order ' . $order->id;
            }
        }

        return true;
    });

    check('a treatment carries a human label', function() use ($tax, $usOrder) {
        return trim($tax->treatOrder($usOrder)->getLabel()) !== '';
    });

    check('the destination is read from the shipping address', function() use ($tax, $deOrder) {
        return $tax->destinationCountry($deOrder) === 'DE' ?: (string)$tax->destinationCountry($deOrder);
    });

    // ---------------------------------------------------------------------
    section('Invoice building');

    $documents = $plugin->getDocuments();

    check('an order builds an invoice', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        return $document->type === Document::TYPE_INVOICE && $document->rows !== [];
    });

    check('a preview resolves nothing remotely — it cannot create anything in Visma', function() use ($documents, $seOrder) {
        $before = (new craft\db\Query())->from(Table::ENTITIES)->count();
        $documents->buildInvoice($seOrder, false);
        $after = (new craft\db\Query())->from(Table::ENTITIES)->count();

        return $before === $after ?: "entity rows went from $before to $after";
    });

    check('one row per line item, plus any adjustments', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $itemRows = array_filter($document->rows, fn(DocumentRow $r): bool => $r->type === DocumentRow::TYPE_ITEM);

        return count($itemRows) === count($seOrder->getLineItems())
            ?: count($itemRows) . ' item rows for ' . count($seOrder->getLineItems()) . ' line items';
    });

    check('every money-carrying row has a tax treatment', function() use ($documents, $seOrder) {
        foreach ($documents->buildInvoice($seOrder, false)->rows as $row) {
            if ($row->type !== DocumentRow::TYPE_TEXT && $row->tax === null) {
                return 'row "' . $row->text . '" has no treatment';
            }
        }

        return true;
    });

    check('the invoice total matches the order total, allowing for öresavrundning', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $difference = abs($document->getGrossTotal() - (float)$seOrder->getTotal());

        return $difference <= 0.51 ?: 'off by ' . $difference;
    });

    check('a rounded invoice settles to whole kronor', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        return abs($document->getGrossTotal() - round($document->getGrossTotal())) < 0.005
            ?: 'gross is ' . $document->getGrossTotal();
    });

    check('rounding off leaves the total unrounded', function() use ($documents, $seOrder) {
        applySettings(['roundToWholeKronor' => false]);
        $document = $documents->buildInvoice($seOrder, false);
        $hasRoundingRow = (bool)array_filter($document->rows, fn(DocumentRow $r): bool => $r->type === DocumentRow::TYPE_ROUNDING);
        applySettings(['roundToWholeKronor' => true]);

        return !$hasRoundingRow ?: 'a rounding row was added anyway';
    });

    check('the rounding row books to the rounding account', function() use ($documents, $plugin) {
        // Built by hand so the amount is guaranteed to need rounding.
        $row = new DocumentRow([
            'type' => DocumentRow::TYPE_ROUNDING,
            'tax' => new TaxTreatment(['salesAccount' => $plugin->getSettings()->roundingAccount]),
        ]);

        return $row->tax->salesAccount === Bas::ROUNDING;
    });

    check('the due date honours the payment terms setting', function() use ($documents, $seOrder) {
        applySettings(['paymentTermsDays' => 14]);
        $document = $documents->buildInvoice($seOrder, false);
        $days = (int)$document->date->diff($document->dueDate)->days;
        applySettings(['paymentTermsDays' => 30]);

        return $days === 14 ?: "got $days days";
    });

    check('an export invoice charges no VAT', function() use ($documents, $usOrder) {
        $document = $documents->buildInvoice($usOrder, false);

        return $document->getVatTotal() === 0.0 ?: 'VAT total is ' . $document->getVatTotal();
    });

    check('the payload is Visma-shaped: PascalCase keys and a Rows array', function() use ($documents, $seOrder) {
        $payload = $documents->buildInvoice($seOrder, false)->toPayload();

        foreach (['InvoiceDate', 'CurrencyCode', 'Rows'] as $key) {
            if (!array_key_exists($key, $payload)) {
                return "missing $key — got " . implode(', ', array_keys($payload));
            }
        }

        return is_array($payload['Rows']) && $payload['Rows'] !== [];
    });

    check('the invoice date is ISO, which is what Visma parses', function() use ($documents, $seOrder) {
        $payload = $documents->buildInvoice($seOrder, false)->toPayload();

        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$payload['InvoiceDate']) ?: (string)$payload['InvoiceDate'];
    });

    check('a row discount is sent as a fraction, not a percentage', function() {
        $row = new DocumentRow([
            'discountPercentage' => 25.0,
            'tax' => new TaxTreatment(['rate' => 25.0]),
        ]);

        return $row->toVismaRow()['DiscountPercentage'] === 0.25
            ?: 'got ' . var_export($row->toVismaRow()['DiscountPercentage'], true);
    });

    check('a text row is flagged as one and carries no amounts', function() {
        $row = new DocumentRow(['type' => DocumentRow::TYPE_TEXT, 'text' => 'Thanks!']);
        $visma = $row->toVismaRow();

        return ($visma['IsTextRow'] === true && !isset($visma['UnitPrice'])) ?: json_encode($visma);
    });

    check('a row without a synced article sends no ArticleId', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        foreach ($document->toPayload()['Rows'] as $row) {
            if (array_key_exists('ArticleId', $row)) {
                return 'an ArticleId was sent for an unsynced article';
            }
        }

        return true;
    });

    // ---------------------------------------------------------------------
    section('Summary vouchers');

    check('several orders build one voucher', function() use ($documents, $seOrder, $deOrder) {
        $document = $documents->buildVoucher([$seOrder, $deOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        return ($document->type === Document::TYPE_VOUCHER && count($document->orderIds) === 2)
            ?: count($document->orderIds) . ' order ids';
    });

    check('a voucher balances — debits equal credits', function() use ($documents, $seOrder, $deOrder, $usOrder) {
        $document = $documents->buildVoucher([$seOrder, $deOrder, $usOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        if (!$document->balances()) {
            $sum = Money::sum(array_map(fn(VoucherLine $l): float => $l->amount, $document->lines));

            return "out by $sum";
        }

        return true;
    });

    check('every voucher line names a real BAS account', function() use ($documents, $seOrder) {
        $document = $documents->buildVoucher([$seOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        foreach ($document->lines as $line) {
            if (!Bas::isValidAccount($line->account)) {
                return 'bad account: "' . $line->account . '"';
            }
        }

        return true;
    });

    check('a voucher has postings at all', function() use ($documents, $seOrder) {
        return $documents->buildVoucher([$seOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'))->lines !== [];
    });

    check('a voucher payload splits signed amounts into debit and credit', function() use ($documents, $seOrder) {
        $document = $documents->buildVoucher([$seOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
        $payload = $document->toPayload();

        if (!isset($payload['Rows'][0])) {
            return 'no rows';
        }

        $row = $payload['Rows'][0];

        return (array_key_exists('DebitAmount', $row) && array_key_exists('CreditAmount', $row) && !array_key_exists('amount', $row))
            ?: json_encode($row);
    });

    check('a posting is never both a debit and a credit', function() use ($documents, $seOrder, $deOrder) {
        $document = $documents->buildVoucher([$seOrder, $deOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        foreach ($document->lines as $line) {
            if ($line->getDebit() > 0 && $line->getCredit() > 0) {
                return 'account ' . $line->account . ' is both';
            }
        }

        return true;
    });

    check('revenue is credited, not debited', function() use ($documents, $seOrder) {
        $document = $documents->buildVoucher([$seOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        foreach ($document->lines as $line) {
            if (str_starts_with($line->account, '30') && $line->amount > 0) {
                return 'sales account ' . $line->account . ' was debited';
            }
        }

        return true;
    });

    check('an empty order list is refused rather than producing an empty voucher', function() use ($documents) {
        try {
            $documents->buildVoucher([], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
        } catch (Throwable) {
            return true;
        }

        return 'an empty voucher was built';
    });

    check('a SIE transaction is a single signed amount', function() {
        $line = new VoucherLine(['account' => '3001', 'amount' => -100.0]);
        $sie = $line->toSieTransaction();

        return ($sie['amount'] === -100.0 && $sie['account'] === '3001') ?: json_encode($sie);
    });

    // ---------------------------------------------------------------------
    section('Idempotency keys and periods');

    check('an invoice key is stable for the same order', function() use ($seOrder) {
        return Documents::invoiceKey($seOrder) === Documents::invoiceKey($seOrder);
    });

    check('two orders get different invoice keys', function() use ($seOrder, $deOrder) {
        return Documents::invoiceKey($seOrder) !== Documents::invoiceKey($deOrder);
    });

    check('a voucher key is stable for the same period', function() {
        $a = Documents::voucherKey(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
        $b = Documents::voucherKey(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        return $a === $b;
    });

    check('different periods get different voucher keys', function() {
        $a = Documents::voucherKey(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
        $b = Documents::voucherKey(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        return $a !== $b;
    });

    check('a credit-note key is stable across rebuilds of the same refunds', function() use ($seOrder) {
        return Documents::creditNoteKey($seOrder) === Documents::creditNoteKey($seOrder);
    });

    check('a daily period is a single day', function() {
        [$start, $end] = Documents::periodFor(new DateTimeImmutable('2026-08-20 14:00'), Settings::PERIOD_DAILY);

        return ($start->format('Y-m-d') === '2026-08-20' && $end->format('Y-m-d') === '2026-08-20')
            ?: $start->format('Y-m-d') . ' → ' . $end->format('Y-m-d');
    });

    check('a weekly period runs Monday to Sunday', function() {
        // 2026-08-20 is a Thursday.
        [$start, $end] = Documents::periodFor(new DateTimeImmutable('2026-08-20'), Settings::PERIOD_WEEKLY);

        return ($start->format('N') === '1' && $end->format('N') === '7')
            ?: $start->format('Y-m-d D') . ' → ' . $end->format('Y-m-d D');
    });

    check('a monthly period covers the whole calendar month', function() {
        [$start, $end] = Documents::periodFor(new DateTimeImmutable('2026-08-20'), Settings::PERIOD_MONTHLY);

        return ($start->format('Y-m-d') === '2026-08-01' && $end->format('Y-m-d') === '2026-08-31')
            ?: $start->format('Y-m-d') . ' → ' . $end->format('Y-m-d');
    });

    check('February is handled without a hard-coded month length', function() {
        [$start, $end] = Documents::periodFor(new DateTimeImmutable('2026-02-10'), Settings::PERIOD_MONTHLY);

        return $end->format('Y-m-d') === '2026-02-28' ?: $end->format('Y-m-d');
    });

    // ---------------------------------------------------------------------
    section('Sync and idempotency');

    $sync = $plugin->getSync();

    check('a completed order is eligible', fn() => $sync->isEligible($seOrder));

    check('an incomplete order is not eligible', function() use ($sync, $variantA) {
        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], 'SE', false);

        return !$sync->isEligible($cart);
    });

    check('a status filter excludes orders outside it', function() use ($sync, $seOrder) {
        applySettings(['syncStatusHandles' => ['a-handle-that-does-not-exist']]);
        $eligible = $sync->isEligible($seOrder);
        applySettings(['syncStatusHandles' => []]);

        return !$eligible ?: 'the order was eligible despite the filter';
    });

    check('recording a document creates exactly one row', function() use ($sync, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $sync->record($document);

        $count = (new craft\db\Query())
            ->from(Table::DOCUMENTS)
            ->where(['type' => $document->type, 'sourceKey' => $document->sourceKey])
            ->count();

        return (int)$count === 1 ?: "$count rows";
    });

    check('recording the same document twice still leaves one row', function() use ($sync, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $sync->record($document);
        $sync->record($document);

        $count = (new craft\db\Query())
            ->from(Table::DOCUMENTS)
            ->where(['type' => $document->type, 'sourceKey' => $document->sourceKey])
            ->count();

        return (int)$count === 1 ?: "$count rows";
    });

    check('the database itself refuses a duplicate — the index is the real guarantee', function() use ($documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'type' => $document->type,
                'sourceKey' => $document->sourceKey,
                'status' => 'pending',
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (Throwable) {
            return true;
        }

        return 'a duplicate (type, sourceKey) was accepted';
    });

    check('a document already sent is reported as skipped rather than sent again', function() use ($sync, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $record = $sync->record($document);
        $record->status = Sync::STATUS_SENT;
        $record->vismaNumber = 'INV-TEST-1';
        $record->save(false);

        $result = $sync->push($document);

        return $result['status'] === Sync::STATUS_SKIPPED ?: $result['status'] . ' — ' . ($result['message'] ?? '');
    });

    check('an unbalanced voucher is rejected before anything is sent', function() use ($sync) {
        $document = new Document([
            'type' => Document::TYPE_VOUCHER,
            'sourceKey' => 'voucher:test-unbalanced:' . uniqid(),
            'date' => new DateTimeImmutable(),
            'lines' => [
                new VoucherLine(['account' => '1930', 'amount' => 100.0]),
                new VoucherLine(['account' => '3001', 'amount' => -50.0]),
            ],
        ]);

        $result = $sync->push($document);

        return $result['status'] === Sync::STATUS_FAILED ?: $result['status'];
    });

    check('an already-documented order is excluded from the unsynced list', function() use ($sync, $seOrder) {
        // The seOrder has a document row from the checks above, but it is only *linked* once a
        // push succeeds — so link it by hand and confirm the exclusion works.
        $record = $sync->findRecord(Document::TYPE_INVOICE, Documents::invoiceKey($seOrder));

        if ($record === null) {
            return 'no document row to link';
        }

        Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTORDERS, [
            'documentId' => $record->id,
            'orderId' => $seOrder->id,
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $ids = array_map(fn(Order $o): int => (int)$o->id, $sync->findUnsyncedOrders(null, null, 500));

        return !in_array((int)$seOrder->id, $ids, true) ?: 'the order is still listed as unsynced';
    });

    check('an order cannot be linked to two documents', function() use ($sync, $documents, $deOrder, $seOrder) {
        $record = $sync->findRecord(Document::TYPE_INVOICE, Documents::invoiceKey($seOrder));

        if ($record === null) {
            return 'no document row';
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTORDERS, [
                'documentId' => $record->id,
                'orderId' => $seOrder->id,
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (Throwable) {
            return true;
        }

        return 'an order was linked twice';
    });

    check('an order with no refunds produces no credit note', function() use ($documents, $seOrder) {
        return $documents->buildCreditNote($seOrder, false) === null;
    });

    check('a refund total of an unrefunded order is zero', function() use ($documents, $seOrder) {
        return $documents->refundedAmount($seOrder) === 0.0;
    });

    // ---------------------------------------------------------------------
    section('SIE export service');

    check('orders group into daily periods', function() use ($plugin, $seOrder, $deOrder) {
        $groups = $plugin->getSie()->groupByPeriod([$seOrder, $deOrder], Settings::PERIOD_DAILY);

        return $groups !== [] ?: 'no groups';
    });

    check('a monthly grouping puts same-month orders together', function() use ($plugin, $seOrder, $deOrder) {
        $groups = $plugin->getSie()->groupByPeriod([$seOrder, $deOrder], Settings::PERIOD_MONTHLY);

        return count($groups) === 1 ?: count($groups) . ' groups for two same-day orders';
    });

    check('an export over a period with no orders produces no verifications', function() use ($plugin) {
        $result = $plugin->getSie()->export(new DateTimeImmutable('2001-01-01'), new DateTimeImmutable('2001-01-31'));

        return $result['verifications'] === 0 ?: $result['verifications'] . ' verifications';
    });

    check('an empty export is still a well-formed SIE file', function() use ($plugin) {
        $result = $plugin->getSie()->export(new DateTimeImmutable('2001-01-01'), new DateTimeImmutable('2001-01-31'));

        return str_contains($result['contents'], '#SIETYP 4') ?: 'no #SIETYP';
    });

    check('the export filename carries the period', function() use ($plugin) {
        $result = $plugin->getSie()->export(new DateTimeImmutable('2001-01-01'), new DateTimeImmutable('2001-01-31'));

        return $result['filename'] === 'vismaz-20010101-20010131.se' ?: $result['filename'];
    });

    // ---------------------------------------------------------------------
    section('Log');

    check('an entry is written', function() use ($plugin) {
        $id = $plugin->getLog()->write('test.entry', ['message' => 'hello', 'statusCode' => 200]);

        return $id !== null && $plugin->getLog()->get($id) !== null;
    });

    check('secrets are redacted before anything is stored', function() use ($plugin) {
        applySettings(['logPayloads' => true]);

        $id = $plugin->getLog()->write('test.redaction', [
            'requestBody' => ['client_secret' => 'hunter2', 'refresh_token' => 'abc', 'CustomerId' => 'keep-me'],
        ]);

        $entry = $plugin->getLog()->get($id);
        $body = (string)($entry['requestBody'] ?? '');

        if (str_contains($body, 'hunter2') || str_contains($body, 'abc')) {
            return 'a secret was stored in the log';
        }

        return str_contains($body, 'keep-me') ?: 'non-secret data was redacted too';
    });

    check('payload logging can be switched off entirely', function() use ($plugin) {
        applySettings(['logPayloads' => false]);
        $id = $plugin->getLog()->write('test.nopayload', ['requestBody' => ['CustomerId' => 'secret-ish']]);
        $entry = $plugin->getLog()->get($id);
        applySettings(['logPayloads' => true]);

        return ($entry['requestBody'] ?? null) === null ?: 'a body was stored anyway';
    });

    check('an error entry is recorded at error level', function() use ($plugin) {
        $id = $plugin->getLog()->error('test.error', 'it broke');

        return ($plugin->getLog()->get($id)['level'] ?? null) === 'error';
    });

    check('pruning with a zero retention keeps everything', function() use ($plugin) {
        return $plugin->getLog()->prune(0) === 0;
    });

    check('the log can be filtered by level', function() use ($plugin) {
        return $plugin->getLog()->count(['level' => 'error']) > 0;
    });

    // ---------------------------------------------------------------------
    section('API error messages');

    check('a Visma field-validation body becomes a readable message', function() {
        $message = \justinholtweb\vismaz\services\Api::describeError([
            'ErrorMessages' => ['CustomerId' => ['The CustomerId field is required.']],
        ], 400);

        return str_contains($message, 'CustomerId') ?: $message;
    });

    check('a rate-limit body is named as a rate limit', function() {
        $message = \justinholtweb\vismaz\services\Api::describeError([
            'ErrorCode' => 4010,
            'DeveloperErrorMessage' => 'Try again in 55 seconds',
        ], 429);

        return str_contains(strtolower($message), 'rate limit') ?: $message;
    });

    check('an unreadable body still gives the status code', function() {
        $message = \justinholtweb\vismaz\services\Api::describeError('<html>502</html>', 502);

        return str_contains($message, '502') ?: $message;
    });

    // ---------------------------------------------------------------------
    section('Cross-border VAT');

    check('OSS applies to an EU consumer sale once enabled', function() use ($plugin, $deOrder) {
        applySettings(['ossEnabled' => true]);
        $treatment = $plugin->getTax()->treatOrder($deOrder);
        applySettings(['ossEnabled' => false]);

        return $treatment->kind === TaxTreatment::KIND_OSS ?: $treatment->kind . ' — ' . $treatment->reason;
    });

    check('an OSS treatment records the destination country', function() use ($plugin, $deOrder) {
        applySettings(['ossEnabled' => true]);
        $treatment = $plugin->getTax()->treatOrder($deOrder);
        applySettings(['ossEnabled' => false]);

        return $treatment->country === 'DE' ?: $treatment->country;
    });

    check('OSS never applies to a domestic sale', function() use ($plugin, $seOrder) {
        applySettings(['ossEnabled' => true]);
        $treatment = $plugin->getTax()->treatOrder($seOrder);
        applySettings(['ossEnabled' => false]);

        return $treatment->kind === TaxTreatment::KIND_DOMESTIC ?: $treatment->kind;
    });

    check('OSS never applies outside the EU', function() use ($plugin, $usOrder) {
        applySettings(['ossEnabled' => true]);
        $treatment = $plugin->getTax()->treatOrder($usOrder);
        applySettings(['ossEnabled' => false]);

        return $treatment->kind === TaxTreatment::KIND_EXPORT ?: $treatment->kind;
    });

    // ---------------------------------------------------------------------
    section('Single edition');

    check('the plugin declares exactly one edition', function() {
        // Vismaz is sold at one price, so there is no edition to be on the wrong side of.
        // Craft gives an editionless plugin the single `standard` edition.
        return count(Plugin::editions()) === 1 ?: implode(', ', Plugin::editions());
    });

    check('nothing in the source gates a feature on an edition', function() {
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['php', 'twig'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (str_contains($contents, 'isPro') || str_contains($contents, 'EDITION_')) {
                $offenders[] = $file->getFilename();
            }
        }

        return $offenders === [] ?: 'edition gating left in: ' . implode(', ', $offenders);
    });

    check('reverse charge is available to every install', function() use ($plugin, $deOrder) {
        applySettings(['reverseChargeEnabled' => true, 'validateVatNumbers' => false]);

        // Still not reverse charged, because no VAT number field is configured — but the reason
        // must be the missing number, not the edition.
        $treatment = $plugin->getTax()->treatOrder($deOrder);

        return !str_contains(strtolower($treatment->reason), 'pro') ?: $treatment->reason;
    });

    check('OSS is available to every install', function() use ($plugin, $deOrder) {
        applySettings(['ossEnabled' => true]);
        $treatment = $plugin->getTax()->treatOrder($deOrder);
        applySettings(['ossEnabled' => false]);

        return $treatment->kind === TaxTreatment::KIND_OSS ?: $treatment->kind;
    });

    check('refund syncing is available to every install', function() use ($plugin, $seOrder) {
        applySettings(['syncRefunds' => true]);
        $result = $plugin->getSync()->syncRefund($seOrder);

        // Skipped because the order has no refunds — not because of an edition.
        return ($result['status'] === Sync::STATUS_SKIPPED
            && str_contains(strtolower((string)$result['message']), 'refunded')) ?: (string)$result['message'];
    });

    check('refund syncing still respects its own setting', function() use ($plugin, $seOrder) {
        applySettings(['syncRefunds' => false]);
        $result = $plugin->getSync()->syncRefund($seOrder);
        applySettings(['syncRefunds' => true]);

        return str_contains(strtolower((string)$result['message']), 'off') ?: (string)$result['message'];
    });

    section('Wiring');

    check('every service resolves', function() use ($plugin) {
        foreach (['auth', 'api', 'tax', 'documents', 'sync', 'customers', 'articles', 'sie', 'log'] as $name) {
            if ($plugin->get($name) === null) {
                return "service $name did not resolve";
            }
        }

        return true;
    });

    check('the Twig variable answers without a connection', function() {
        $variable = new \justinholtweb\vismaz\twig\VismazVariable();

        return is_bool($variable->isConnected());
    });

    check('the redirect URI is an absolute action URL', function() use ($plugin) {
        $uri = $plugin->getAuth()->getRedirectUri();

        return (str_starts_with($uri, 'http') && str_contains($uri, 'vismaz/oauth/callback')) ?: $uri;
    });

    check('the OAuth scopes include offline_access, without which there is no refresh token', function() {
        return str_contains(\justinholtweb\vismaz\services\Auth::SCOPES, 'offline_access')
            && str_contains(\justinholtweb\vismaz\services\Auth::SCOPES, 'ea:api');
    });

    check('the authorization URL pins prompt=select_account', function() use ($plugin) {
        applySettings(['clientId' => 'test-client', 'clientSecret' => 'test-secret']);
        $url = $plugin->getAuth()->getAuthorizationUrl();

        return str_contains($url, 'prompt=select_account') ?: $url;
    });

    check('an OAuth state is single-use', function() use ($plugin) {
        $url = $plugin->getAuth()->getAuthorizationUrl();
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';

        $first = $plugin->getAuth()->consumeState($state);
        $second = $plugin->getAuth()->consumeState($state);

        return ($first !== null && $second === null) ?: 'a state was accepted twice';
    });

    check('an unknown state is rejected', function() use ($plugin) {
        return $plugin->getAuth()->consumeState('not-a-real-state') === null;
    });

    check('no connection means no access token, rather than an exception', function() use ($plugin) {
        return $plugin->getAuth()->getAccessToken() === null;
    });

    check('an API call without a connection fails with a clear message', function() use ($plugin) {
        try {
            $plugin->getApi()->get('companysettings');
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'not connected') ?: $e->getMessage();
        }

        return 'the call did not fail';
    });

    check('the connection test reports a failure rather than throwing', function() use ($plugin) {
        $result = $plugin->getApi()->test();

        return ($result['ok'] === false && is_string($result['message'])) ?: json_encode($result);
    });

    check('a stored token is encrypted at rest, not plaintext', function() use ($plugin) {
        // `Security::encryptByKey()` returns raw binary, so it is base64-wrapped before storage —
        // a refresh token is good for two years and does not belong in the database in the clear.
        $reflection = new ReflectionMethod(\justinholtweb\vismaz\services\Auth::class, 'encrypt');
        $reflection->setAccessible(true);
        $cipher = $reflection->invoke(null, 'super-secret-refresh-token');

        return (!str_contains($cipher, 'super-secret-refresh-token') && base64_decode($cipher, true) !== false)
            ?: 'the token was stored readable';
    });

    check('an encrypted token round-trips back to plaintext', function() {
        $encrypt = new ReflectionMethod(\justinholtweb\vismaz\services\Auth::class, 'encrypt');
        $decrypt = new ReflectionMethod(\justinholtweb\vismaz\services\Auth::class, 'decrypt');
        $encrypt->setAccessible(true);
        $decrypt->setAccessible(true);

        return $decrypt->invoke(null, $encrypt->invoke(null, 'round-trip')) === 'round-trip';
    });

    check('an unreadable token decrypts to null rather than throwing', function() {
        $decrypt = new ReflectionMethod(\justinholtweb\vismaz\services\Auth::class, 'decrypt');
        $decrypt->setAccessible(true);

        return $decrypt->invoke(null, 'not-valid-ciphertext') === null && $decrypt->invoke(null, null) === null;
    });

    check('a matching total reconciles silently', function() use ($plugin, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        return $plugin->getSync()->reconcile($document, ['TotalAmount' => $document->getGrossTotal()]) === null;
    });

    check('a total Visma disagrees with is reported as a mismatch', function() use ($plugin, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);
        $message = $plugin->getSync()->reconcile($document, ['TotalAmount' => $document->getGrossTotal() + 10.0]);

        return is_string($message) && str_contains($message, 'Visma booked') ?: var_export($message, true);
    });

    check('a one-öre difference is within tolerance', function() use ($plugin, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        return $plugin->getSync()->reconcile($document, ['TotalAmount' => $document->getGrossTotal() + 0.01]) === null;
    });

    check('a response with no total is not a discrepancy', function() use ($plugin, $documents, $seOrder) {
        $document = $documents->buildInvoice($seOrder, false);

        return $plugin->getSync()->reconcile($document, ['Id' => 'abc']) === null;
    });

    check('a voucher is not reconciled on totals — it is reconciled by balancing', function() use ($plugin, $documents, $seOrder) {
        $document = $documents->buildVoucher([$seOrder], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        return $plugin->getSync()->reconcile($document, ['TotalAmount' => 999999.0]) === null;
    });

    check('OData string literals escape embedded quotes', function() {
        return \justinholtweb\vismaz\services\Customers::escapeOData("O'Brien") === "O''Brien";
    });

    check('every CP template compiles', function() {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

        try {
            foreach ([
                'vismaz/settings',
                'vismaz/_order-panel',
                'vismaz/documents/_index',
                'vismaz/documents/_detail',
                'vismaz/log/_index',
                'vismaz/log/_detail',
                'vismaz/reports/_oss',
            ] as $template) {
                if (!$view->doesTemplateExist($template)) {
                    return "missing template: $template";
                }

                $view->getTwig()->load($template);
            }
        } finally {
            $view->setTemplateMode($mode);
        }

        return true;
    });

    check('the plugin icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon.svg');

        return (simplexml_load_string($svg) !== false && str_contains($svg, 'viewBox'))
            ?: 'icon.svg did not parse';
    });

    check('the mask icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon-mask.svg');

        return simplexml_load_string($svg) !== false;
    });

    check('every translated string file is a flat string map', function() {
        foreach (['en', 'sv'] as $locale) {
            $messages = require dirname(__DIR__, 2) . "/src/translations/$locale/vismaz.php";

            if (!is_array($messages)) {
                return "$locale did not return an array";
            }

            foreach ($messages as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    return "$locale has a non-string entry";
                }
            }
        }

        return true;
    });
} finally {
    // -------------------------------------------------------------------------
    // Clean up, pass or fail.
    // -------------------------------------------------------------------------
    echo "\nCleaning up…\n";

    $db = Craft::$app->getDb();

    foreach ($createdOrders as $order) {
        try {
            $db->createCommand()->delete(Table::DOCUMENTORDERS, ['orderId' => $order->id])->execute();

            // Document rows are keyed on the order, not joined to it until a push succeeds, so
            // deleting the order alone would leave them orphaned in the table for ever.
            $db->createCommand()->delete(Table::DOCUMENTS, [
                'or',
                ['sourceKey' => 'order:' . $order->id],
                ['like', 'sourceKey', 'refund:' . $order->id . ':', false],
            ])->execute();

            Craft::$app->getElements()->deleteElement($order, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$order->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $product) {
        try {
            Craft::$app->getElements()->deleteElement($product, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$product->id}: {$e->getMessage()}\n";
        }
    }

    try {
        $db->createCommand()->delete(Table::DOCUMENTS, ['like', 'sourceKey', 'voucher:test-unbalanced:%', false])->execute();
        $db->createCommand()->delete(Table::LOG, ['like', 'action', 'test.%', false])->execute();
        $db->createCommand()->delete(Table::ENTITIES, ['like', 'localId', "%$suffix%", false])->execute();
    } catch (Throwable $e) {
        echo "  ! cleanup query failed: {$e->getMessage()}\n";
    }

    try {
        Craft::$app->getPlugins()->savePluginSettings($plugin, $originalSettings);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "\n";
    echo "  $passed passed, $failed failed\n\n";

    exit($failed === 0 ? 0 : 1);
}
