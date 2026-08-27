<?php
namespace App\Mail;
use App\Models\MachineIntakeCase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
class MachineIntakeBchMail extends Mailable
{
 use Queueable,SerializesModels;
 public function __construct(public MachineIntakeCase $case){}
 public function envelope():Envelope{return new Envelope(subject:$this->case->bch_email_subject);}
 public function content():Content{return new Content(view:'emails.machine-intake-bch');}
 public function attachments():array{$a=[Attachment::fromStorageDisk('public',$this->case->bch_package_path)->as(basename($this->case->bch_package_path))];foreach($this->case->documents as $d)$a[]=Attachment::fromStorageDisk($d->storage_disk,$d->storage_path)->as($d->original_name);return $a;}
}
