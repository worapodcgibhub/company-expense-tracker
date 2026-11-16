@props(['charge'])

{{-- Status badges of one actual charge (App\Models\SubscriptionCharge). --}}
@if (! $charge->isCharged())
    <x-ui.status-badge tone="slate" :dot="false">ไม่ถูกตัดเงิน</x-ui.status-badge>
@endif
@if ($charge->needsThbAmount())
    <x-ui.status-badge tone="amber" :dot="false" data-test="missing-thb-badge">รอยอดเงินบาท</x-ui.status-badge>
@endif
@if ($charge->entered_after_close)
    <x-ui.status-badge tone="red" :dot="false" data-test="after-close-badge">ย้อนหลัง (หลังปิดงวด)</x-ui.status-badge>
@endif
@if ($charge->isVerified())
    <x-ui.status-badge tone="emerald" :dot="false" data-test="verified-badge">ตรวจสอบแล้ว</x-ui.status-badge>
@endif
