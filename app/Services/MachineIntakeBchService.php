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
 public function prepare(MachineIntakeCase $case,array $data):MachineIntakeCase
 {
  abort_unless($case->status==='CONFIRMED',422,'Hồ sơ phải được xác nhận trước khi tạo email.');
  $path='machine-intakes/'.$case->reference.'/bch/'.$case->reference.'-tao-ma.xlsx';Excel::store(new MachineIntakeBchExport($case->load('project')),$path,'public');
  $case->update(['bch_email_to'=>$data['to'],'bch_email_cc'=>$data['cc']??null,'bch_email_subject'=>$data['subject'],'bch_email_body'=>$data['body'],'bch_package_path'=>$path]);return $case->refresh();
 }
 public function send(MachineIntakeCase $case,User $user):MachineIntakeCase
 {
  abort_unless($case->status==='CONFIRMED'&&$case->bch_package_path,422,'Hãy tạo và xem trước gói BCH trước khi gửi.');
  if(in_array(config('mail.default'),['log','array'],true))throw new BusinessRuleException('Email thật chưa được cấu hình. Hãy cấu hình SMTP trước khi gửi BCH.');
  $mail=Mail::to(array_map('trim',explode(',',$case->bch_email_to)));if($case->bch_email_cc)$mail->cc(array_map('trim',explode(',',$case->bch_email_cc)));$mail->send(new MachineIntakeBchMail($case->load('documents')));
  $case->update(['status'=>'WAIT_ASSET_CODE','email_sent_at'=>now(),'email_sent_by'=>$user->id,'email_message_id'=>$case->reference.'-'.now()->format('YmdHis')]);MachineIntakeEvent::create(['machine_intake_case_id'=>$case->id,'user_id'=>$user->id,'event'=>'intake.bch_email_sent','properties'=>['to'=>$case->bch_email_to,'cc'=>$case->bch_email_cc],'occurred_at'=>now()]);return $case->refresh();
 }
}
