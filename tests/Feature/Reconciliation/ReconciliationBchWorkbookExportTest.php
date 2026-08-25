<?php

namespace Tests\Feature\Reconciliation;

use App\Exports\ReconciliationBchWorkbookExport;
use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationBchWorkbookExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_creates_one_sheet_per_bch_and_full_month_for_each_machine(): void
    {
        $period = ReconciliationPeriod::query()->create([
            'name' => 'Tháng 8/2026', 'type' => 'MONTHLY',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'CONFIRMED',
        ]);
        $project = DB::table('projects')->insertGetId(['name' => 'Dương Kinh']);
        $bch = DB::table('command_centers')->insertGetId(['name' => 'VA DK']);
        $machine = DB::table('machines')->insertGetId([
            'asset_code' => 'T-XN0042', 'company' => 'VINCONS', 'chassis_no' => 'TEST-EXPORT-15-1',
            'machine_type' => 'Xe nước', 'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false,
        ]);
        $assignment = DB::table('machine_assignments')->insertGetId([
            'machine_id' => $machine, 'project_id' => $project, 'command_center_id' => $bch,
            'time_in' => '2026-08-10 07:00:00', 'time_out' => '2026-08-20 17:30:00',
        ]);
        DB::table('reconciliation_rows')->insert([
            'reconciliation_period_id' => $period->id, 'machine_id' => $machine,
            'machine_assignment_id' => $assignment, 'work_date' => '2026-08-10',
            'segment_start' => '07:00:00', 'segment_end' => '23:59:59',
            'project_id' => $project, 'command_center_id' => $bch,
            'confirmed_check_in' => '07:00:00', 'confirmed_check_out' => '17:30:00',
            'regular_morning_start' => '07:00:00', 'regular_morning_end' => '11:00:00',
            'regular_afternoon_start' => '13:30:00', 'regular_afternoon_end' => '16:30:00',
            'overtime_afternoon_start' => '16:30:00', 'overtime_afternoon_end' => '17:30:00',
            'regular_minutes' => 420, 'ot_afternoon_minutes' => 60,
            'work_location' => 'DK', 'work_content' => 'Rửa đường',
            'status' => 'CONFIRMED',
        ]);

        $sheets = (new ReconciliationBchWorkbookExport($period))->sheets();
        $data = $sheets[0]->array();

        $this->assertCount(1, $sheets);
        $this->assertSame('VA DK', $sheets[0]->title());
        $this->assertCount(37, $data); // 5 dòng đầu + 1 dòng tổng máy + 31 ngày.
        $this->assertSame('I', $data[5][0]);
        $this->assertSame('T-XN0042', $data[6][2]);
        $this->assertSame('01/08/2026', $data[6][3]);
        $this->assertNull($data[6][7]); // 01/08 chưa thuộc BCH.
        $this->assertSame('07:00', $data[15][7]); // 10/08 thuộc BCH.
        $this->assertSame('Rửa đường', $data[15][23]);

        $scopedSheets = (new ReconciliationBchWorkbookExport($period, [
            'date_from' => '2026-08-08',
            'date_to' => '2026-08-14',
        ]))->sheets();
        $scopedData = $scopedSheets[0]->array();

        $this->assertCount(13, $scopedData); // 5 dòng đầu + 1 dòng tổng máy + 7 ngày.
        $this->assertSame('08/08/2026', $scopedData[6][3]);
        $this->assertSame('07:00', $scopedData[8][7]); // 10/08 trong phạm vi đã chọn.
    }
}
