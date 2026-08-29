<?php

namespace App\Mail;

use App\Models\MachineIntakeCase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MachineIntakeBchMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MachineIntakeCase $case,
        public array $sender,
        public array $replyToConfig = [],
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = filled($this->replyToConfig['address'] ?? null)
            ? [new Address($this->replyToConfig['address'], $this->replyToConfig['name'] ?? null)]
            : [];

        return new Envelope(
            from: new Address($this->sender['address'], $this->sender['name'] ?? null),
            replyTo: $replyTo,
            subject: $this->case->bch_email_subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.machine-intake-bch');
    }

    public function attachments(): array
    {
        $attachments = [
            Attachment::fromStorageDisk('public', $this->case->bch_package_path)
                ->as(basename($this->case->bch_package_path)),
        ];
        foreach ($this->case->documents as $document) {
            $attachments[] = Attachment::fromStorageDisk($document->storage_disk, $document->storage_path)
                ->as($document->original_name);
        }

        return $attachments;
    }
}
