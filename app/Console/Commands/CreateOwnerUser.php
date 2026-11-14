<?php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Question\Question;

/**
 * Creates the company Owner account from the terminal.
 *
 * Security notes:
 * - The password is only ever read through hidden terminal input. There is
 *   deliberately no --password option (it would end up in shell history).
 * - Hidden-input fallback is disabled: if the terminal cannot hide input the
 *   command aborts instead of echoing the password.
 * - The password is never printed or logged; it is stored only as a hash
 *   (User model `hashed` cast).
 */
class CreateOwnerUser extends Command
{
    use ProfileValidationRules;

    /**
     * @var string
     */
    protected $signature = 'user:create-owner';

    /**
     * @var string
     */
    protected $description = 'สร้างบัญชี Owner (เจ้าของบริษัท) โดยกรอกรหัสผ่านแบบซ่อนข้อความ';

    public function handle(): int
    {
        $this->info('สร้างบัญชี Owner สำหรับ Company Expense Tracker');
        $this->line('รหัสผ่านต้องยาวอย่างน้อย 12 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก ตัวเลข และสัญลักษณ์');
        $this->newLine();

        if (User::where('role', UserRole::Owner)->exists()
            && ! $this->confirm('มีบัญชี Owner อยู่แล้ว ต้องการสร้าง Owner เพิ่มอีกบัญชีหรือไม่?', false)) {
            $this->warn('ยกเลิก: ไม่ได้สร้างบัญชีใหม่');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('ชื่อ-นามสกุล'));
        $email = Str::lower(trim((string) $this->ask('อีเมล')));
        $password = $this->askHidden('รหัสผ่าน');
        $passwordConfirmation = $this->askHidden('ยืนยันรหัสผ่าน');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => ['required', 'string', $this->strongPassword(), 'confirmed'],
        ], [
            'email.unique' => 'อีเมลนี้มีบัญชีอยู่แล้ว',
            'password.confirmed' => 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน',
        ]);

        // Drop the plain-text confirmation as soon as it has been validated.
        $passwordConfirmation = null;

        if ($validator->fails()) {
            $password = null;

            $this->error('สร้างบัญชีไม่สำเร็จ:');

            foreach ($validator->errors()->all() as $message) {
                $this->line("  - {$message}");
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password): User {
            $user = new User;

            // forceFill: `role` and `email_verified_at` are not mass-assignable on purpose.
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password, // hashed by the model's `hashed` cast
                'role' => UserRole::Owner,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        $password = null;

        $this->newLine();
        $this->info('สร้างบัญชี Owner เรียบร้อยแล้ว');
        $this->line("  ชื่อ: {$user->name}");
        $this->line("  อีเมล: {$user->email}");
        $this->line('  สิทธิ์: '.UserRole::Owner->label());
        $this->newLine();
        $this->line('แนะนำ: หลังเข้าสู่ระบบครั้งแรก ให้เปิดใช้การยืนยันตัวตนสองขั้น (2FA) ที่หน้าตั้งค่า');

        return self::SUCCESS;
    }

    /**
     * Ask for a value without echoing it. Never falls back to visible input.
     */
    private function askHidden(string $label): string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return (string) $this->output->askQuestion($question);
    }

    /**
     * Strong password policy for the Owner account, applied in every
     * environment (the app-wide default is only strict in production).
     * `uncompromised()` is intentionally not used: it calls an external API.
     */
    private function strongPassword(): Password
    {
        return Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols();
    }
}
