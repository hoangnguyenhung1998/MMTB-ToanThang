<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exports\MachineIntakeBchExport;
use App\Mail\MachineIntakeBchMail;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class MachineIntakeBchService
{
    public function senderOptions(): array
    {
        return collect(config('machine_intake_mail.senders', []))
            ->map(fn (array $sender, string $key) => [
                'key' => $key,
                'label' => $sender['label'],
                'address' => $sender['address'],
                'configured' => $this->isConfigured($sender),
            ])->values()->all();
    }

    public function defaultSender(): string
    {
        $default = (string) config('machine_intake_mail.default_sender', 'test');

        return array_key_exists($default, config('machine_intake_mail.senders', [])) ? $default : 'test';
    }

    public function prepare(MachineIntakeCase $case, array $data): MachineIntakeCase
    {
        abort_unless($case->status === 'CONFIRMED', 422, 'Hồ sơ phải được xác nhận trước khi tạo email.');
        $this->sender($data['sender_profile']);

        $path = 'machine-intakes/'.$case->reference.'/bch/'.$case->reference.'-tao-ma.xlsx';
        Excel::store(new MachineIntakeBchExport($case->load('project')), $path, 'public');
        $case->update([
            'bch_email_to' => $data['to'],
            'bch_email_cc' => $data['cc'] ?? null,
            'bch_email_subject' => $data['subject'],
            'bch_email_body' => $data['body'],
            'bch_sender_profile' => $data['sender_profile'],
            'bch_package_path' => $path,
        ]);

        return $case->refresh();
    }

    public function send(MachineIntakeCase $case, User $user): MachineIntakeCase
    {
        abort_unless($case->status === 'CONFIRMED' && $case->bch_package_path, 422, 'Hãy tạo và xem trước gói BCH trước khi gửi.');

        $profileKey = $case->bch_sender_profile ?: $this->defaultSender();
        $sender = $this->sender($profileKey);
        if (! $this->isConfigured($sender)) {
            throw new BusinessRuleException("{$sender['label']} chưa được cấu hình SMTP đầy đủ.");
        }

        $mail = Mail::mailer($sender['mailer'])->to($this->addresses($case->bch_email_to));
        if ($case->bch_email_cc) {
            $mail->cc($this->addresses($case->bch_email_cc));
        }
        $mail->send(new MachineIntakeBchMail($case->load('documents'), $sender, config('machine_intake_mail.reply_to', [])));

        $case->update([
            'status' => 'WAIT_ASSET_CODE',
            'email_sent_at' => now(),
            'email_sent_by' => $user->id,
            'email_message_id' => $case->reference.'-'.now()->format('YmdHis'),
        ]);
        MachineIntakeEvent::create([
            'machine_intake_case_id' => $case->id,
            'user_id' => $user->id,
            'event' => 'intake.bch_email_sent',
            'properties' => [
                'to' => $case->bch_email_to,
                'cc' => $case->bch_email_cc,
                'sender_profile' => $profileKey,
                'sender_address' => $sender['address'],
            ],
            'occurred_at' => now(),
        ]);

        return $case->refresh();
    }

    private function sender(string $key): array
    {
        $sender = config("machine_intake_mail.senders.{$key}");
        if (! is_array($sender)) {
            throw new BusinessRuleException('Tài khoản gửi email không hợp lệ.');
        }

        return $sender;
    }

    private function isConfigured(array $sender): bool
    {
        $mailer = config('mail.mailers.'.($sender['mailer'] ?? ''), []);

        return filled($sender['address'] ?? null)
            && filled($mailer['host'] ?? null)
            && filled($mailer['username'] ?? null)
            && filled($mailer['password'] ?? null);
    }

    private function addresses(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
