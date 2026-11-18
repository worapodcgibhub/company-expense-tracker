<?php

namespace Database\Seeders;

use App\Enums\BankAccountKind;
use App\Enums\BillingCycle;
use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Enums\ChargeOutcome;
use App\Enums\Currency;
use App\Enums\PaymentMethodType;
use App\Enums\ReceiptKind;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\ChargeReceipt;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\BillingSchedule;
use App\Support\ReceiptStorage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sample subscriptions with ~6 months of actual charges and documents, for
 * trying out and presenting the app. Run it explicitly:
 *
 *   php artisan db:seed --class=SampleDataSeeder
 *
 * (DatabaseSeeder does NOT call it.)
 *
 * Safety:
 * - Only ADDS data. It never deletes, resets or updates existing records
 *   (the only existing rows it touches are none: it uses the current Owner
 *   and an existing active card as references).
 * - Safe to run again: a sample subscription whose name + vendor already
 *   exists (any status, compared like the duplicate check) is skipped with
 *   all of its history, so nothing is ever added twice.
 * - Everything runs in ONE transaction: if anything fails, nothing is
 *   added, and any document file written during the run is removed again.
 * - No users are created. If no active card exists, one sample card is
 *   added (last four digits only).
 * - History is produced with the same domain methods the pages use
 *   (recordCharge, revise, attachReceipt, markReceiptUnavailable,
 *   changeStatus) with the clock moved back to each moment, so dates,
 *   next charge dates, history and totals are consistent. All dates are
 *   relative to the day it is run.
 * - Phase 3.1: also adds sample bank accounts (last four digits only) and
 *   payment methods (transfer, PromptPay/QR, direct debit, petty cash),
 *   found by name so a second run adds nothing.
 * - Phase 3.1e-5: channelCatalog() adds the recurring costs paid through the
 *   bank (colocation and domain by transfer, internet by direct debit,
 *   freelancer by PromptPay) on those methods, with the same history,
 *   receipts and idempotency as the card samples. They are NOT part of the
 *   eight card / Auto samples. If their method (or its account) has been
 *   switched off, that sample is skipped, never reactivated. One-off costs
 *   (illustration, office equipment, travel) and the advance payment are not
 *   subscriptions and wait for their own phases.
 * - Accounting periods are NOT closed and nothing is verified (that needs
 *   an Accountant): those remain decisions for real people.
 */
class SampleDataSeeder extends Seeder
{
    public const MARKER = 'ข้อมูลตัวอย่าง (Sample data)';

    /**
     * Baht per US dollar by age of the cycle in months (0 = latest).
     */
    private const THB_PER_USD = [0 => 35.20, 1 => 35.45, 2 => 35.80, 3 => 36.10, 4 => 35.90, 5 => 36.40];

    private CarbonImmutable $realNow;

    private ?CarbonInterface $previousTestNow = null;

    /** @var list<string> files stored during this run (removed if it fails) */
    private array $storedPaths = [];

    /**
     * name, vendor, pdf (ASCII name for the sample PDF), category, currency,
     * amount (minor units), cycle, next_in (days from today to the next
     * charge; negative = overdue, not recorded), plus optional scenarios:
     * history (cycles before next, default 6 monthly / 1 yearly),
     * deltas (per cycle, oldest first), skip (age), pause_after (cycles),
     * unavailable (age => reason), replace (age), revise (age => mode),
     * tax_invoice (true = THB vendor issues tax invoices).
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            ['name' => 'ChatGPT Plus', 'vendor' => 'OpenAI', 'pdf' => 'OpenAI', 'category' => SubscriptionCategory::Software, 'currency' => Currency::USD, 'amount' => 2_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 3, 'revise' => [1 => 'fill_thb']],
            ['name' => 'Claude Pro', 'vendor' => 'Anthropic', 'pdf' => 'Anthropic', 'category' => SubscriptionCategory::Software, 'currency' => Currency::USD, 'amount' => 2_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 12, 'replace' => 3, 'pause_after' => 3],
            ['name' => 'Canva Pro', 'vendor' => 'Canva', 'pdf' => 'Canva', 'category' => SubscriptionCategory::Design, 'currency' => Currency::THB, 'amount' => 33_900, 'cycle' => BillingCycle::Monthly, 'next_in' => 9, 'skip' => 2],
            ['name' => 'Cloud Server (Droplet)', 'vendor' => 'DigitalOcean', 'pdf' => 'DigitalOcean', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::USD, 'amount' => 2_400, 'cycle' => BillingCycle::Monthly, 'next_in' => 2, 'deltas' => [0, 120, -80, 260, 40, 310], 'revise' => [1 => 'actual_amount']],
            ['name' => 'Cloudflare Pro', 'vendor' => 'Cloudflare', 'pdf' => 'Cloudflare', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::USD, 'amount' => 2_500, 'cycle' => BillingCycle::Monthly, 'next_in' => -2, 'unavailable' => [2 => 'ดาวน์โหลดใบเสร็จย้อนหลังจากระบบผู้ให้บริการไม่ได้']],
            ['name' => 'ค่า VPS', 'vendor' => 'ผู้ให้บริการ VPS (ตัวอย่าง)', 'pdf' => 'VPS Provider', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::THB, 'amount' => 180_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 6, 'tax_invoice' => true],
            ['name' => 'ค่า AI สำหรับคอนเทนต์', 'vendor' => 'ผู้ให้บริการ AI (ตัวอย่าง)', 'pdf' => 'AI Content Tool', 'category' => SubscriptionCategory::Software, 'currency' => Currency::THB, 'amount' => 75_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 8, 'unavailable' => [4 => 'ผู้ให้บริการส่งเพียงอีเมลแจ้งชำระเงิน ไม่ออกใบเสร็จ']],
            ['name' => 'Web Hosting (Business)', 'vendor' => 'Hostinger', 'pdf' => 'Hostinger', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::USD, 'amount' => 4_788, 'cycle' => BillingCycle::Yearly, 'next_in' => 200],
        ];
    }

    /**
     * Phase 3.1e-5: sample recurring costs paid through the bank, not the
     * card. They are separate from catalog() (the eight card / Auto samples)
     * and are NOT counted in it. `method` is the name of one of
     * paymentMethods(); the other keys are the same as in catalog().
     *
     * - Colocation (transfer, Manual) and domain (transfer, yearly, Manual)
     * - Internet (direct debit, Auto)
     * - Freelancer graphics (PromptPay / QR via SCB, Manual)
     *
     * One-off costs (illustration, office equipment, travel) and the advance
     * payment are NOT here: they are not subscriptions and wait for their own
     * phases.
     *
     * @return list<array<string, mixed>>
     */
    public static function channelCatalog(): array
    {
        return [
            ['name' => 'ค่าเช่า Colocation', 'vendor' => 'ผู้ให้บริการ Colocation (ตัวอย่าง)', 'pdf' => 'Colocation Provider', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::THB, 'amount' => 850_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 5, 'tax_invoice' => true, 'method' => 'โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)'],
            ['name' => 'ค่า Internet สำนักงาน', 'vendor' => 'ผู้ให้บริการอินเทอร์เน็ต (ตัวอย่าง)', 'pdf' => 'Internet Provider', 'category' => SubscriptionCategory::Communication, 'currency' => Currency::THB, 'amount' => 129_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 10, 'tax_invoice' => true, 'method' => 'หักบัญชีอัตโนมัติ KBank (ตัวอย่าง)'],
            ['name' => 'ค่าโดเมนเว็บไซต์', 'vendor' => 'ผู้ให้บริการโดเมน (ตัวอย่าง)', 'pdf' => 'Domain Registrar', 'category' => SubscriptionCategory::CloudHosting, 'currency' => Currency::THB, 'amount' => 45_000, 'cycle' => BillingCycle::Yearly, 'next_in' => 200, 'method' => 'โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)'],
            ['name' => 'ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก', 'vendor' => 'ฟรีแลนซ์ (ตัวอย่าง)', 'pdf' => 'Freelance Designer', 'category' => SubscriptionCategory::Design, 'currency' => Currency::THB, 'amount' => 350_000, 'cycle' => BillingCycle::Monthly, 'next_in' => 4, 'deltas' => [0, 0, 50_000, 0, 0, 0], 'unavailable' => [3 => 'ฟรีแลนซ์ส่งเพียงสลิปโอน ไม่ออกใบเสร็จ'], 'method' => 'PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)'],
        ];
    }

    public function run(): void
    {
        $this->realNow = CarbonImmutable::now();
        $this->previousTestNow = Carbon::getTestNow();

        $owner = User::query()->where('role', UserRole::Owner->value)->whereNull('deactivated_at')->orderBy('id')->first()
            ?? throw new RuntimeException('ไม่พบบัญชี Owner ที่ใช้งานอยู่ สร้างด้วย php artisan user:create-owner ก่อน');

        $todo = array_values(array_filter(self::catalog(), fn (array $item) => ! $this->alreadyExists($item['name'], $item['vendor'])));

        $channelTodo = array_values(array_filter(self::channelCatalog(), fn (array $item) => ! $this->alreadyExists($item['name'], $item['vendor'])));

        $channelsMissing = $this->channelsMissing();

        if ($todo === [] && $channelTodo === [] && ! $channelsMissing) {
            $this->say('ข้อมูลตัวอย่างมีครบแล้ว ไม่ได้เพิ่มอะไร');

            return;
        }

        $added = ['accounts' => 0, 'methods' => 0];
        $channelAdded = 0;
        $channelUnusable = [];

        try {
            DB::transaction(function () use ($todo, $channelTodo, $owner, $channelsMissing, &$added, &$channelAdded, &$channelUnusable) {
                if ($channelsMissing) {
                    $added = $this->seedPaymentChannels($owner);
                }

                if ($todo !== []) {
                    $method = $this->methodOfCard($this->cardToUse());

                    foreach ($todo as $item) {
                        $this->seedSubscription($item, $owner, $method);
                    }
                }

                foreach ($channelTodo as $item) {
                    $method = PaymentMethod::query()->with(['creditCard', 'bankAccount'])->where('name', $item['method'])->first();

                    // A method someone switched off (or whose account is off) is
                    // never reactivated or replaced: the sample is skipped.
                    if ($method === null || ! $method->isUsable()) {
                        $channelUnusable[] = $item['name'];

                        continue;
                    }

                    $this->seedSubscription($item, $owner, $method);
                    $channelAdded++;
                }
            });
        } catch (Throwable $e) {
            foreach ($this->storedPaths as $path) {
                ReceiptStorage::discardUnsaved($path);
            }

            throw $e;
        } finally {
            $this->resetClock();
        }

        $this->say('เพิ่มบัญชีธนาคารตัวอย่าง '.$added['accounts'].' บัญชี ช่องทางชำระเงิน '.$added['methods'].' ช่องทาง');
        $this->say('เพิ่ม Subscription ตัวอย่างที่ชำระด้วยบัตร '.count($todo).' รายการ (ข้าม '.(count(self::catalog()) - count($todo)).' รายการที่มีอยู่แล้ว)');
        $this->say('เพิ่ม Subscription ตัวอย่างที่ชำระผ่านธนาคาร/PromptPay '.$channelAdded.' รายการ (ข้าม '.(count(self::channelCatalog()) - count($channelTodo)).' รายการที่มีอยู่แล้ว)');

        if ($channelUnusable !== []) {
            $this->say('ข้ามเพราะช่องทางหรือบัญชีถูกปิดใช้งาน: '.implode(', ', $channelUnusable));
        }
    }

    /**
     * The payment method of the card (the card always has its own).
     */
    private function methodOfCard(CreditCard $card): PaymentMethod
    {
        return PaymentMethod::query()->where('credit_card_id', $card->id)->first()
            ?? throw new RuntimeException("บัตร (รหัส {$card->id}) ยังไม่มีช่องทางชำระเงิน กรุณาตั้งค่าก่อน");
    }

    /**
     * Sample bank accounts: name, bank, last four digits only.
     *
     * @return list<array{name: string, bank: string, last_four: string}>
     */
    public static function bankAccounts(): array
    {
        return [
            ['name' => 'KBank บัญชีบริษัท', 'bank' => 'ธนาคารกสิกรไทย (KBank)', 'last_four' => '5678'],
            ['name' => 'SCB บัญชีบริษัท', 'bank' => 'ธนาคารไทยพาณิชย์ (SCB)', 'last_four' => '9012'],
        ];
    }

    /**
     * Sample payment methods: name, type, account name (null = none).
     * PromptPay / QR is a bank transfer type linked to the SCB account (there
     * is no separate type); the name says so because methods have no notes.
     * An advance-payment method is not here: it needs its own type (later).
     *
     * @return list<array{name: string, type: PaymentMethodType, account: ?string}>
     */
    public static function paymentMethods(): array
    {
        return [
            ['name' => 'โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)', 'type' => PaymentMethodType::BankTransfer, 'account' => 'KBank บัญชีบริษัท'],
            ['name' => 'PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)', 'type' => PaymentMethodType::BankTransfer, 'account' => 'SCB บัญชีบริษัท'],
            ['name' => 'หักบัญชีอัตโนมัติ KBank (ตัวอย่าง)', 'type' => PaymentMethodType::DirectDebit, 'account' => 'KBank บัญชีบริษัท'],
            ['name' => 'เงินสดย่อยสำนักงาน (ตัวอย่าง)', 'type' => PaymentMethodType::Cash, 'account' => null],
        ];
    }

    private function channelsMissing(): bool
    {
        return count(self::bankAccounts()) > BankAccount::query()->whereIn('name', array_column(self::bankAccounts(), 'name'))->count()
            || count(self::paymentMethods()) > PaymentMethod::query()->whereIn('name', array_column(self::paymentMethods(), 'name'))->count();
    }

    /**
     * Adds only what is missing, found by name (any status); never updates.
     *
     * @return array{accounts: int, methods: int}
     */
    private function seedPaymentChannels(User $owner): array
    {
        $added = ['accounts' => 0, 'methods' => 0];
        $accounts = [];

        foreach (self::bankAccounts() as $item) {
            $account = BankAccount::query()->where('name', $item['name'])->first();

            if ($account === null) {
                $account = new BankAccount;
                $account->forceFill([
                    'name' => $item['name'],
                    'bank' => $item['bank'],
                    'account_name' => 'บริษัท ตัวอย่าง จำกัด',
                    'last_four' => $item['last_four'],
                    'kind' => BankAccountKind::Current,
                    'notes' => self::MARKER,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ])->save();
                $added['accounts']++;
            }

            $accounts[$item['name']] = $account;
        }

        foreach (self::paymentMethods() as $item) {
            if (PaymentMethod::query()->where('name', $item['name'])->exists()) {
                continue;
            }

            $method = new PaymentMethod;
            $method->forceFill([
                'name' => $item['name'],
                'type' => $item['type'],
                'settlement' => $item['type']->defaultSettlement(),
                'bank_account_id' => $item['account'] === null ? null : $accounts[$item['account']]->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ])->save();
            $added['methods']++;
        }

        return $added;
    }

    /**
     * Same comparison as the duplicate warning (case and spaces ignored),
     * but over every status, so a cancelled one also counts.
     */
    private function alreadyExists(string $name, string $vendor): bool
    {
        $name = Subscription::normalize($name);
        $vendor = Subscription::normalize($vendor);

        return Subscription::query()->get(['name', 'vendor'])
            ->contains(fn (Subscription $s) => Subscription::normalize($s->name) === $name && Subscription::normalize($s->vendor) === $vendor);
    }

    /**
     * The first active card; one sample card only if there is none.
     */
    private function cardToUse(): CreditCard
    {
        $card = CreditCard::query()->whereNull('deactivated_at')->orderBy('id')->first();

        if ($card !== null) {
            return $card;
        }

        $card = new CreditCard;
        $card->forceFill([
            'name' => 'บัตรตัวอย่าง',
            'issuer' => 'ธนาคารตัวอย่าง',
            'network' => CardNetwork::Visa,
            'kind' => CardKind::Credit,
            'last_four' => '4242',
            'holder_name' => 'SAMPLE COMPANY',
            'notes' => self::MARKER,
        ]);

        // Phase 3.1b: the card and its payment method are saved together.
        $card->saveWithPaymentMethod();

        return $card;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedSubscription(array $item, User $owner, PaymentMethod $method): void
    {
        /** @var BillingCycle $cycle */
        $cycle = $item['cycle'];
        /** @var Currency $currency */
        $currency = $item['currency'];
        $amount = (int) $item['amount'];

        $anchor = $this->realNow->startOfDay()->addDays((int) $item['next_in']);
        $billingDay = $anchor->day;
        $dues = $this->duesBefore($anchor, $cycle, $billingDay, (int) ($item['history'] ?? ($cycle === BillingCycle::Monthly ? 6 : 1)));
        $pauseAfter = isset($item['pause_after']) ? (int) $item['pause_after'] : null;

        // Created two weeks before the first cycle (or a month ago).
        $firstDue = $dues[0] ?? $anchor;
        $createdAt = $firstDue->subDays(14)->setTime(10, 0);
        $this->at($createdAt->lessThan($this->realNow->subMonth()) ? $createdAt : $this->realNow->subMonth());

        $subscription = new Subscription;
        $subscription->forceFill([
            'name' => $item['name'],
            'vendor' => $item['vendor'],
            'category' => $item['category'],
            'responsible_user_id' => $owner->id,
            'credit_card_id' => $method->credit_card_id,
            'payment_method_id' => $method->id,
            'billing_cycle' => $cycle,
            'billing_day' => $billingDay,
            'next_charge_date' => $firstDue,
            'currency' => $currency,
            'estimated_amount' => $amount,
            'remind_days_before' => 3,
            'notes' => self::MARKER,
            'is_sample' => true,
            'status' => SubscriptionStatus::Active,
            'status_changed_at' => now(),
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ])->save();

        $subscription->statusChanges()->create([
            'from_status' => null,
            'to_status' => SubscriptionStatus::Active,
            'changed_by' => $owner->id,
        ]);

        foreach ($dues as $i => $due) {
            if ($pauseAfter !== null && $i >= $pauseAfter) {
                break;
            }

            // Whole months between the cycle and today (0 = within the last month).
            $age = (int) floor(abs($due->diffInMonths($this->realNow)));
            $this->seedCycle($item, $subscription, $owner, $method, $due, $i, $age);
        }

        if ($pauseAfter !== null && isset($dues[$pauseAfter - 1])) {
            $this->at($dues[$pauseAfter - 1]->addDays(5)->setTime(11, 0));
            $subscription->refresh()->changeStatus(SubscriptionStatus::Paused, $owner, 'ระงับชั่วคราวระหว่างทดลองใช้เครื่องมืออื่น');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedCycle(array $item, Subscription $subscription, User $owner, PaymentMethod $method, CarbonImmutable $due, int $index, int $age): void
    {
        /** @var Currency $currency */
        $currency = $item['currency'];
        $foreign = $currency !== Currency::THB;
        $skipped = isset($item['skip']) && (int) $item['skip'] === $age;
        $revise = $item['revise'][$age] ?? null;

        $estimate = (int) $item['amount'];
        $actual = $estimate + (int) ($item['deltas'][$index] ?? 0);
        $chargedOn = $foreign && $index % 2 === 1 ? $due->subDay() : $due;

        $recordedAmount = $revise === 'actual_amount' ? $estimate : $actual;
        $thb = $foreign && $age >= 1 && $revise !== 'fill_thb' ? $this->toThb($actual, $age) : null;

        // Recorded the day after the charge, in the morning.
        $this->at($due->addDay()->setTime(9, 30));

        $charge = $subscription->refresh()->recordCharge($due, [
            'outcome' => $skipped ? ChargeOutcome::Skipped : ChargeOutcome::Charged,
            'charged_on' => $skipped ? null : $chargedOn,
            'amount' => $skipped ? 0 : $recordedAmount,
            'amount_thb' => $thb,
            'payment_method_id' => $method->id,
            'notes' => $skipped ? 'ได้รับเดือนฟรีจากโปรโมชันต่ออายุ' : null,
        ], $owner);

        if ($skipped) {
            return;
        }

        // Corrections made a few days later (kept in the history).
        if ($revise !== null) {
            $this->at($due->addDays(6)->setTime(16, 15));
            $charge->revise([
                'outcome' => ChargeOutcome::Charged,
                'charged_on' => $chargedOn,
                'amount' => $actual,
                'amount_thb' => $foreign ? $this->toThb($actual, $age) : null,
                'payment_method_id' => $method->id,
                'notes' => null,
            ], $owner, $revise === 'fill_thb' ? 'ได้ใบแจ้งยอดบัตรแล้ว กรอกยอดเงินบาทที่ธนาคารตัดจริง' : 'ปรับเป็นยอดจริงตามใบแจ้งยอดบัตร (มีค่าใช้งานเพิ่ม)');
        }

        $this->seedDocuments($item, $charge->refresh(), $owner, $chargedOn, $index, $age);
    }

    /**
     * Older cycles have documents, last month about half, this month none
     * yet ("waiting for receipt"); a few are "no receipt available".
     *
     * @param  array<string, mixed>  $item
     */
    private function seedDocuments(array $item, SubscriptionCharge $charge, User $owner, CarbonImmutable $chargedOn, int $index, int $age): void
    {
        $unavailable = $item['unavailable'][$age] ?? null;

        if (is_string($unavailable)) {
            $this->at($chargedOn->addDays(4)->setTime(13, 0));
            $charge->markReceiptUnavailable($owner, $unavailable);

            return;
        }

        $attach = $age >= 2 || ($age === 1 && $index % 2 === 0);

        if (! $attach) {
            return;
        }

        $taxInvoice = (bool) ($item['tax_invoice'] ?? false);
        $kind = $taxInvoice ? ReceiptKind::TaxInvoice : ReceiptKind::Receipt;
        $number = $taxInvoice ? 'INV-'.$chargedOn->format('Ym').'-'.str_pad((string) ($charge->id % 1000), 3, '0', STR_PAD_LEFT) : null;
        // The date printed on the document differs from the charge date.
        $documentDate = $taxInvoice ? $chargedOn->addDay() : $chargedOn;

        $this->at($chargedOn->addDays(3)->setTime(14, 0));
        $receipt = $this->attach($charge, $item, $kind, $number, $documentDate, $owner, $chargedOn, false);

        if (isset($item['replace']) && (int) $item['replace'] === $age) {
            $this->at($chargedOn->addDays(5)->setTime(10, 30));
            $this->attach($charge, $item, $kind, $number, $documentDate, $owner, $chargedOn, true, $receipt);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function attach(SubscriptionCharge $charge, array $item, ReceiptKind $kind, ?string $number, CarbonImmutable $documentDate, User $owner, CarbonImmutable $chargedOn, bool $clearCopy, ?ChargeReceipt $replaces = null): ChargeReceipt
    {
        $vendor = (string) $item['pdf'];
        $amount = ($charge->currency === Currency::THB ? 'THB ' : 'USD ').number_format($charge->amount / 100, 2);
        $file = $this->samplePdf($vendor.' - '.($kind === ReceiptKind::TaxInvoice ? 'Tax invoice' : 'Receipt'), [
            'SAMPLE DOCUMENT (not a real receipt)',
            'Vendor: '.$vendor,
            'Date: '.$documentDate->format('Y-m-d'),
            'Amount: '.$amount,
            $number !== null ? 'No: '.$number : 'Charged on: '.$chargedOn->format('Y-m-d'),
            $clearCopy ? 'Clear copy' : '',
        ], strtolower(str_replace(' ', '-', $vendor)).'-'.$chargedOn->format('Y-m').($clearCopy ? '-clear' : '').'.pdf');

        try {
            $receipt = $charge->attachReceipt($file, $kind, $number, $documentDate, $owner, $replaces, $replaces !== null ? 'ไฟล์เดิมสแกนไม่ชัด แนบฉบับที่อ่านได้แทน' : null);
            $this->storedPaths[] = $receipt->path;

            return $receipt;
        } finally {
            @unlink($file->getPathname());
        }
    }

    /**
     * $count cycle dates before $anchor (oldest first), on the billing day.
     *
     * @return list<CarbonImmutable>
     */
    private function duesBefore(CarbonImmutable $anchor, BillingCycle $cycle, int $billingDay, int $count): array
    {
        $dues = [];

        for ($i = $count; $i >= 1; $i--) {
            $month = $cycle === BillingCycle::Monthly
                ? $anchor->startOfMonth()->subMonthsNoOverflow($i)
                : $anchor->startOfMonth()->subYears($i);

            $dues[] = BillingSchedule::onBillingDay($month, $billingDay);
        }

        return $dues;
    }

    private function toThb(int $usdCents, int $age): int
    {
        return (int) round($usdCents * (self::THB_PER_USD[$age] ?? 35.50));
    }

    /**
     * Moves the clock to $moment (never past the real "now").
     */
    private function at(CarbonImmutable $moment): void
    {
        $moment = $moment->greaterThan($this->realNow) ? $this->realNow : $moment;

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    /**
     * Puts the clock back exactly as it was before the run.
     */
    private function resetClock(): void
    {
        Carbon::setTestNow($this->previousTestNow);
        CarbonImmutable::setTestNow($this->previousTestNow);
    }

    /**
     * A tiny valid one-page PDF (ASCII text) written to a temp file.
     *
     * @param  list<string>  $lines
     */
    private function samplePdf(string $title, array $lines, string $name): UploadedFile
    {
        $escape = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $text = 'BT /F1 16 Tf 40 250 Td ('.$escape($title).') Tj /F1 11 Tf';
        foreach (array_filter($lines) as $line) {
            $text .= ' 0 -22 Td ('.$escape($line).') Tj';
        }
        $text .= ' ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 300] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'sample-receipt-');

        if ($path === false || file_put_contents($path, $pdf) === false) {
            throw new RuntimeException('Could not write a sample receipt file.');
        }

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function say(string $message): void
    {
        $this->command?->info($message);
    }
}
