<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Owner-only user management. Every action is authorized again here
 * (UserPolicy), not only by the route, because Livewire actions can be
 * called directly. `role`, `email_verified_at` and `deactivated_at` are not
 * mass-assignable, so they are only ever set explicitly with forceFill().
 * There is deliberately no delete: users are deactivated instead.
 */
new #[Title('ผู้ใช้งานและสิทธิ์')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $resettingId = null;

    public string $name = '';
    public string $email = '';
    public string $role = 'staff';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * Active users first, then Owner → Accountant → Staff, then by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()
            ->orderByRaw('deactivated_at IS NOT NULL')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'accountant' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();
    }

    public function openCreate(): void
    {
        $this->authorize('create', User::class);

        $this->resetForm();

        Flux::modal('user-form')->show();
    }

    public function openEdit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('update', $user);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? User::findOrFail($this->editingId) : null;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', User::class);

        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));

        $rules = [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($editing?->id),
            'role' => ['required', Rule::enum(UserRole::class)],
        ];

        if ($editing === null) {
            $rules['password'] = $this->strongPasswordRules();
        }

        $validated = $this->validate($rules, [
            'email.unique' => 'อีเมลนี้มีบัญชีอยู่แล้ว',
            'role.required' => 'กรุณาเลือกสิทธิ์การใช้งาน',
            'role.enum' => 'สิทธิ์การใช้งานไม่ถูกต้อง',
        ], [
            'name' => 'ชื่อ-นามสกุล',
            'email' => 'อีเมล',
            'role' => 'สิทธิ์การใช้งาน',
        ]);

        $role = UserRole::from($validated['role']);

        if ($editing !== null && $role !== $editing->role && Gate::denies('changeRole', $editing)) {
            $this->addError('role', 'ไม่สามารถเปลี่ยนสิทธิ์ของบัญชีตัวเองได้');

            return;
        }

        $user = $editing ?? new User;

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $role,
        ]);

        if ($editing === null) {
            // Created by the Owner, so the address is trusted (no email is sent in v1).
            $user->forceFill([
                'password' => $validated['password'], // hashed by the model's `hashed` cast
                'email_verified_at' => now(),
            ]);
        }

        $user->save();

        $this->resetForm();
        unset($this->users);

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: $editing ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มผู้ใช้เรียบร้อยแล้ว');
    }

    public function openResetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('resetPassword', $user);

        $this->resetForm();
        $this->resettingId = $user->id;
        $this->name = $user->name;

        Flux::modal('reset-password')->show();
    }

    public function resetPassword(): void
    {
        $user = User::findOrFail($this->resettingId ?? 0);
        $this->authorize('resetPassword', $user);

        $validated = $this->validate(['password' => $this->strongPasswordRules()]);

        // A new remember token also ends any "remember me" sign-ins.
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $this->resetForm();

        Flux::modal('reset-password')->close();
        Flux::toast(variant: 'success', text: 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว');
    }

    public function deactivate(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('deactivate', $user);

        $user->forceFill([
            'deactivated_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        unset($this->users);

        Flux::toast(variant: 'success', text: "ปิดการใช้งานบัญชี {$user->name} แล้ว");
    }

    public function reactivate(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('reactivate', $user);

        $user->forceFill(['deactivated_at' => null])->save();

        unset($this->users);

        Flux::toast(variant: 'success', text: "เปิดการใช้งานบัญชี {$user->name} แล้ว");
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'resettingId', 'name', 'email', 'role', 'password', 'password_confirmation']);
        $this->resetValidation();
    }
}; ?>

@php
    $roleTones = ['owner' => 'indigo', 'accountant' => 'cyan', 'staff' => 'slate'];
    $editingSelf = $editingId !== null && $editingId === auth()->id();
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    {{-- Page header --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">ผู้ใช้งานและสิทธิ์</h1>
            <p class="mt-1 text-sm text-slate-500">เพิ่มบัญชีให้ฝ่ายบัญชีและพนักงาน กำหนดสิทธิ์ และปิดการใช้งานเมื่อไม่ใช้แล้ว</p>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="openCreate" data-test="add-user-button" class="w-full sm:w-auto">
            เพิ่มผู้ใช้
        </flux:button>
    </header>

    {{-- What each role can do --}}
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([
            [UserRole::Owner, 'จัดการผู้ใช้ บัตรเครดิต และเห็นข้อมูลทั้งหมด'],
            [UserRole::Accountant, 'ดูรายการ รับทราบรายการที่ส่งมา และตรวจรายการก่อนปิดงบ'],
            [UserRole::Staff, 'เพิ่มรายการและแนบเอกสาร แก้ไม่ได้หลังส่งบัญชีแล้ว'],
        ] as [$case, $description])
            <x-ui.card class="!p-4">
                <x-ui.status-badge :tone="$roleTones[$case->value]">{{ $case->label() }}</x-ui.status-badge>
                <p class="mt-2 text-sm text-slate-600">{{ $description }}</p>
            </x-ui.card>
        @endforeach
    </div>

    {{-- User list (stacked rows: one layout for phone and desktop) --}}
    <x-ui.card :padding="false" class="overflow-hidden">
        <ul class="divide-y divide-slate-100" data-test="user-list">
            @foreach ($this->users as $user)
                <li wire:key="user-{{ $user->id }}" @class([
                    'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                    'bg-slate-50/70' => ! $user->isActive(),
                ])>
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$user->name" :initials="$user->initials()" size="sm" />
                        <div class="min-w-0">
                            <p @class(['truncate font-medium', 'text-slate-900' => $user->isActive(), 'text-slate-500 line-through' => ! $user->isActive()])>
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <span class="text-xs font-normal text-slate-500">(คุณ)</span>
                                @endif
                            </p>
                            <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <x-ui.status-badge :tone="$roleTones[$user->role->value]">{{ $user->role->label() }}</x-ui.status-badge>

                        @if ($user->isActive())
                            <x-ui.status-badge tone="emerald">ใช้งานอยู่</x-ui.status-badge>
                        @else
                            <x-ui.status-badge tone="red">ปิดใช้งาน</x-ui.status-badge>
                        @endif

                        @if ($user->two_factor_confirmed_at)
                            <x-ui.status-badge tone="emerald" :dot="false">2FA เปิดแล้ว</x-ui.status-badge>
                        @else
                            <x-ui.status-badge tone="amber" :dot="false">ยังไม่เปิด 2FA</x-ui.status-badge>
                        @endif

                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="'จัดการ '.$user->name" />

                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="openEdit({{ $user->id }})">แก้ไขข้อมูล</flux:menu.item>

                                @can('resetPassword', $user)
                                    <flux:menu.item icon="key" wire:click="openResetPassword({{ $user->id }})">ตั้งรหัสผ่านใหม่</flux:menu.item>
                                @endcan

                                @can('deactivate', $user)
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        icon="no-symbol"
                                        variant="danger"
                                        wire:click="deactivate({{ $user->id }})"
                                        wire:confirm="ปิดการใช้งานบัญชี {{ $user->name }}? ผู้ใช้นี้จะเข้าสู่ระบบไม่ได้ แต่ข้อมูลเดิมยังอยู่ครบ"
                                    >ปิดการใช้งาน</flux:menu.item>
                                @endcan

                                @can('reactivate', $user)
                                    <flux:menu.separator />
                                    <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $user->id }})">เปิดการใช้งานอีกครั้ง</flux:menu.item>
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    {{-- Create / edit --}}
    <flux:modal name="user-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'แก้ไขข้อมูลผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' }}</flux:heading>
                <flux:text class="mt-1">อีเมลใช้สำหรับเข้าสู่ระบบ</flux:text>
            </div>

            <flux:input wire:model="name" label="ชื่อ-นามสกุล" required autocomplete="off" />
            <flux:input wire:model="email" type="email" label="อีเมล" required autocomplete="off" />

            <div>
                <flux:select wire:model="role" label="สิทธิ์การใช้งาน" :disabled="$editingSelf">
                    @foreach (UserRole::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($editingSelf)
                    <flux:text class="mt-1 text-xs">เปลี่ยนสิทธิ์ของบัญชีตัวเองไม่ได้ เพื่อให้มีเจ้าของบริษัทเหลืออย่างน้อย 1 คนเสมอ</flux:text>
                @endif
            </div>

            @if (! $editingId)
                <flux:input wire:model="password" type="password" label="รหัสผ่านเริ่มต้น" required autocomplete="new-password" />
                <flux:input wire:model="password_confirmation" type="password" label="ยืนยันรหัสผ่าน" required autocomplete="new-password" />
                <flux:callout icon="information-circle" color="sky">
                    <flux:callout.text>
                        อย่างน้อย 12 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก ตัวเลข และสัญลักษณ์
                        แจ้งรหัสผ่านให้ผู้ใช้โดยตรง และให้เปลี่ยนรหัสผ่านเองที่หน้าตั้งค่าหลังเข้าสู่ระบบครั้งแรก
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-user-button">บันทึก</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Reset password --}}
    <flux:modal name="reset-password" class="w-full max-w-md">
        <form wire:submit="resetPassword" class="space-y-5">
            <div>
                <flux:heading size="lg">ตั้งรหัสผ่านใหม่</flux:heading>
                <flux:text class="mt-1">สำหรับ {{ $name }} รหัสผ่านเดิมจะใช้ไม่ได้ทันที</flux:text>
            </div>

            <flux:input wire:model="password" type="password" label="รหัสผ่านใหม่" required autocomplete="new-password" />
            <flux:input wire:model="password_confirmation" type="password" label="ยืนยันรหัสผ่าน" required autocomplete="new-password" />
            <flux:text class="text-xs">อย่างน้อย 12 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก ตัวเลข และสัญลักษณ์</flux:text>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto">บันทึกรหัสผ่าน</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
