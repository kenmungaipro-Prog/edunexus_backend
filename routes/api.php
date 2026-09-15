<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Accounting\ChartOfAccountController;
use App\Http\Controllers\Api\Accounting\JournalEntryController;
use App\Http\Controllers\Api\Finance\FeeCategoryController;
use App\Http\Controllers\Api\Finance\FeeStructureController;
use App\Http\Controllers\Api\Finance\FinanceDashboardController;
use App\Http\Controllers\Api\Finance\InvoiceController;
use App\Http\Controllers\Api\Finance\PaymentController;
use App\Http\Controllers\Api\Finance\StudentFinanceController;
use App\Http\Controllers\Api\Finance\ReceiptController;
use App\Http\Controllers\Api\Payments\MpesaController;
use App\Http\Controllers\Api\Finance\PaymentReconciliationController;
use App\Http\Controllers\Api\Payments\ParentPaymentController;
use App\Http\Controllers\Api\{
    AuthController,
    StudentController,
    TeacherController,
    AttendanceController,
    ExamController,
    GradeController,
    FeeController,
    TimetableController,
    LibraryController,
    EventController,
    MessageController,
    AnalyticsController,
    ClassRoomController,
    DashboardController,
    FeeTypeController,
    ParentProfileController,
};
use App\Http\Controllers\Api\Transport\TransportAssignmentController;
use App\Http\Controllers\Api\Transport\TransportController;
use App\Http\Controllers\Api\Transport\TransportFleetController;
use App\Http\Controllers\Api\Transport\TransportTripController;


Route::post('/debug-419', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'session_id'        => session()->getId(),
        'csrf_token'        => csrf_token(),
        'middleware'        => app(\Illuminate\Routing\Router::class)->getMiddleware(),
        'all_headers'       => $request->headers->all(),
    ]);
});
    // NOTE: M-Pesa webhook routes will be registered inside the `v1` group below
    // but remain outside the auth middleware so Safaricom can POST without tokens.

// ============================================================
// Public Routes
// ============================================================
Route::prefix('v1')->group(function () {

    Route::post('/auth/login',   [AuthController::class, 'login']);

    // M-Pesa Webhooks (outside auth middleware)
    Route::prefix('payments/mpesa')->group(function () {
        Route::post('stk-callback', [MpesaController::class, 'stkCallback'])->name('api.mpesa.stk-callback');
        Route::post('c2b-validation', [MpesaController::class, 'c2bValidation'])->name('api.mpesa.c2b-validation');
        Route::post('c2b-confirmation', [MpesaController::class, 'c2bConfirmation'])->name('api.mpesa.c2b-confirmation');
        Route::post('stk-push', [ParentPaymentController::class, 'publicInitiateStkPush']);
    });

    // Co-op Bank 400222 Webhooks (public endpoints)
    Route::prefix('payments/coop')->group(function () {
        // Validation endpoint
        Route::post('400222/validation', [\App\Http\Controllers\Api\Payments\CoopBankController::class, 'validation'])->name('api.coop.validation');
        // Confirmation endpoint where the bank posts successful payments
        Route::post('400222/confirmation', [\App\Http\Controllers\Api\Payments\CoopBankController::class, 'confirmation'])->name('api.coop.confirmation');
        // Instant Notification Service (INS) endpoint for Co-op Bank (normalized INS payload)
        Route::post('ins', [\App\Http\Controllers\Api\Payments\CoopBankController::class, 'instantNotification'])->name('api.coop.ins');
    });

    // ============================================================
    // Authenticated Routes
    // ============================================================
    Route::middleware(['auth:sanctum', 'school'])->group(function () {

        // Auth
        Route::post('/auth/logout',          [AuthController::class, 'logout']);
        Route::get('/auth/me',               [AuthController::class, 'me']);
        Route::post('/auth/refresh',         [AuthController::class, 'refresh']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        // ── Dashboard ──────────────────────────────────────────
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats',    [DashboardController::class, 'stats']);
            Route::get('/activity', [DashboardController::class, 'activity']);
            Route::get('/charts',   [DashboardController::class, 'charts']);
        });

        // ── Students ───────────────────────────────────────────
        Route::middleware('role:admin,teacher,receptionist')->group(function () {
            // Static routes MUST come before apiResource to avoid {student} wildcard matching them.
            Route::get('students/stats',                  [StudentController::class, 'stats']);
            Route::post('students/import',                [StudentController::class, 'import']);
            Route::apiResource('students', StudentController::class);
            Route::get('students/{student}/report-card',  [StudentController::class, 'reportCard']);
            Route::get('students/{student}/attendance',   [StudentController::class, 'attendanceHistory']);
            Route::get('students/{student}/fees',         [StudentController::class, 'feeHistory']);
        });

        // ── Teachers ───────────────────────────────────────────
        Route::middleware('role:admin')->group(function () {
            Route::apiResource('teachers', TeacherController::class);
            Route::get('teachers/{teacher}/timetable',  [TeacherController::class, 'timetable']);
            Route::get('teachers/{teacher}/performance',[TeacherController::class, 'performance']);
        });

        // ── Parents ────────────────────────────────────────────
        Route::middleware('role:admin,receptionist,parent')->group(function () {
            Route::get('parents/stats', [ParentProfileController::class, 'stats']);
            Route::get('parents/me', [ParentProfileController::class, 'me']);
            Route::apiResource('parents', ParentProfileController::class);
        });

        // ── Classes ────────────────────────────────────────────
        Route::middleware('role:admin,teacher')->group(function () {
            Route::apiResource('classes', ClassRoomController::class);
        });

        // ── Subjects ───────────────────────────────────────────
        Route::middleware('role:admin')->group(function () {
            Route::apiResource('subjects', \App\Http\Controllers\Api\SubjectController::class);
        });

        // ── Academic Sessions ───────────────────────────────────
        Route::apiResource('academic-sessions', \App\Http\Controllers\Api\AcademicSessionController::class)
            ->middleware('role:admin');

        // ── Attendance ─────────────────────────────────────────
        Route::prefix('attendance')->group(function () {
            Route::get('/',            [AttendanceController::class, 'index']);
            Route::post('/mark',       [AttendanceController::class, 'mark'])->middleware('role:admin,teacher');
            Route::put('/{id}',        [AttendanceController::class, 'update'])->middleware('role:admin,teacher');
            Route::get('/stats',       [AttendanceController::class, 'stats']);
            Route::get('/low',         [AttendanceController::class, 'lowAttendance']);
            Route::get('/export',      [AttendanceController::class, 'export']);
        });

        // ── Timetable ──────────────────────────────────────────
        Route::prefix('timetable')->group(function () {
            Route::get('/',            [TimetableController::class, 'index']);
            Route::post('/',           [TimetableController::class, 'store'])->middleware('role:admin');
            Route::put('/{slot}',      [TimetableController::class, 'update'])->middleware('role:admin');
            Route::delete('/{slot}',   [TimetableController::class, 'destroy'])->middleware('role:admin');
            Route::post('/generate',   [TimetableController::class, 'autoGenerate'])->middleware('role:admin');
        });

        // ── Exams & Grades ─────────────────────────────────────
        Route::apiResource('exams', ExamController::class)->middleware('role:admin,teacher');
        Route::prefix('grades')->group(function () {
            Route::post('/',               [GradeController::class, 'store'])->middleware('role:admin,teacher');
            Route::get('/distribution',    [GradeController::class, 'distribution']);
            Route::get('/subject-perf',    [GradeController::class, 'subjectPerformance']);
        });

        // ── Fees ───────────────────────────────────────────────
        Route::middleware('role:admin,accountant')->group(function () {
            Route::apiResource('fee-types', FeeTypeController::class)->except(['show']);
            Route::post('fees/collect',         [FeeController::class, 'collect']);
            Route::get('fees/summary',          [FeeController::class, 'summary']);
            Route::get('fees/defaulters',       [FeeController::class, 'defaulters']);
            Route::get('fees/export',           [FeeController::class, 'export']);
            Route::get('fees/{fee}/receipt',    [FeeController::class, 'receipt']);
            Route::apiResource('fees', FeeController::class);
            
        });

        Route::middleware('role:superadmin,admin,accountant')->prefix('finance')->group(function () {
            Route::get('dashboard/summary', [FinanceDashboardController::class, 'summary']);
            Route::get('dashboard/recent-payments', [FinanceDashboardController::class, 'recentPayments']);

            Route::apiResource('fee-categories', FeeCategoryController::class);
            Route::apiResource('fee-structures', FeeStructureController::class);

            Route::post('invoices/generate', [InvoiceController::class, 'generate']);
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
            Route::apiResource('invoices', InvoiceController::class)->except(['update', 'destroy']);

            Route::post('payments/collect', [PaymentController::class, 'collect']);
            Route::get('payments/mpesa-status', [PaymentController::class, 'mpesaStatus']);
            Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
            Route::get('payments/{payment}/allocation-results', [PaymentController::class, 'allocationResults']);
            Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse']);
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);
            Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);
            Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);

            // Receipts
            Route::apiResource('receipts', ReceiptController::class)->only(['index', 'show'])->parameters(['receipts' => 'receipt']);
        });

        Route::middleware('role:admin,accountant,bursar,parent')->group(function () {
            Route::get('students/{student}/finance-summary', [StudentFinanceController::class, 'summary']);
            Route::get('students/{student}/statement', [StudentFinanceController::class, 'statement']);
        });

        // ── Accounting ──────────────────────────────────────────
        Route::middleware('role:superadmin,admin,accountant')->prefix('accounting')->group(function () {
            // Chart of Accounts
            Route::get('accounts/tree', [ChartOfAccountController::class, 'tree']);
            Route::get('accounts/by-type/{type}', [ChartOfAccountController::class, 'byType']);
            Route::apiResource('accounts', ChartOfAccountController::class);

            // Journal Entries
            Route::post('journal-entries/{journalEntry}/submit', [JournalEntryController::class, 'submit']);
            Route::post('journal-entries/{journalEntry}/approve', [JournalEntryController::class, 'approve']);
            Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);
            Route::apiResource('journal-entries', JournalEntryController::class);

            // Reports
            Route::get('reports/trial-balance', [JournalEntryController::class, 'trialBalance']);
            Route::get('reports/income-statement', [JournalEntryController::class, 'incomeStatement']);
            Route::get('reports/balance-sheet', [JournalEntryController::class, 'balanceSheet']);
            Route::get('reports/cash-flow', [JournalEntryController::class, 'cashFlow']);
        });

       // ── Library ────────────────────────────────────────────
        Route::middleware('role:admin,librarian')->group(function () {
            // 1. Specific routes first
            Route::get('books/stats',          [LibraryController::class, 'stats']);
            Route::get('books/overdue',        [LibraryController::class, 'overdue']);
            Route::post('books/{book}/issue',  [LibraryController::class, 'issue']);
            Route::post('books/{book}/return', [LibraryController::class, 'return']);
            
            // 2. Resource routes last
            Route::apiResource('books', LibraryController::class);
        });

        // ── Transport ──────────────────────────────────────────
        Route::prefix('transport')->group(function () {
            Route::get('/vehicles',                     [TransportFleetController::class, 'vehicles']);
            Route::post('/vehicles',                    [TransportFleetController::class, 'storeVehicle']);
            Route::get('/vehicles/{vehicle}',           [TransportFleetController::class, 'showVehicle']);
            Route::put('/vehicles/{vehicle}',           [TransportFleetController::class, 'updateVehicle']);
            Route::delete('/vehicles/{vehicle}',        [TransportFleetController::class, 'deleteVehicle']);
            Route::get('/drivers',                      [TransportFleetController::class, 'drivers']);
            Route::post('/drivers',                     [TransportFleetController::class, 'storeDriver']);
            Route::get('/drivers/{driver}',             [TransportFleetController::class, 'showDriver']);
            Route::put('/drivers/{driver}',             [TransportFleetController::class, 'updateDriver']);
            Route::delete('/drivers/{driver}',          [TransportFleetController::class, 'deleteDriver']);
            Route::get('/routes/my',                    [TransportController::class, 'myRoute'])->middleware('role:driver');
            Route::get('/routes/my/eta',                [TransportController::class, 'myRouteEta'])->middleware('role:driver');
            Route::get('/geofences/my',                 [TransportController::class, 'myGeofences'])->middleware('role:driver');
            Route::get('/geofence-events/my',           [TransportController::class, 'myGeofenceEvents'])->middleware('role:driver');
            Route::get('/trips',                        [TransportTripController::class, 'listTrips'])->middleware('role:admin,superadmin');
            Route::get('/trips/my',                     [TransportTripController::class, 'myTrips'])->middleware('role:driver');
            Route::get('/trips/{trip}',                 [TransportTripController::class, 'showTrip'])->middleware('role:driver,admin,superadmin');
            Route::get('/trips/{trip}/locations',       [TransportTripController::class, 'tripLocations'])->middleware('role:driver,admin,superadmin');
            Route::post('/routes/{route}/trips',        [TransportTripController::class, 'scheduleTrip'])->middleware('role:admin,receptionist');
            Route::post('/trips/{trip}/start',          [TransportTripController::class, 'startTrip'])->middleware('role:driver,admin,receptionist');
            Route::post('/trips/{trip}/end',            [TransportTripController::class, 'endTrip'])->middleware('role:driver,admin,receptionist');
            Route::get('/routes/{route}/stops',          [TransportController::class, 'stopsForRoute'])->middleware('role:admin,receptionist');
            Route::post('/routes/{route}/stops',         [TransportController::class, 'storeStop'])->middleware('role:admin,receptionist');
            Route::put('/routes/{route}/stops/{stop}',   [TransportController::class, 'updateStop'])->middleware('role:admin,receptionist');
            Route::delete('/routes/{route}/stops/{stop}',[TransportController::class, 'deleteStop'])->middleware('role:admin,receptionist');
            Route::get('/trips/{trip}/stops',             [TransportTripController::class, 'tripStops'])->middleware('role:driver,admin,superadmin');
            Route::post('/trips/{trip}/stops/{stop}',     [TransportTripController::class, 'storeTripStop'])->middleware('role:driver,admin,superadmin');
            Route::put('/trips/{trip}/stops/{tripStop}',  [TransportTripController::class, 'updateTripStop'])->middleware('role:driver,admin,superadmin');
            Route::delete('/trips/{trip}/stops/{tripStop}', [TransportTripController::class, 'deleteTripStop'])->middleware('role:driver,admin,superadmin');
            Route::get('/trips/{trip}/students',          [TransportTripController::class, 'tripStudents'])->middleware('role:driver,admin,superadmin');
            Route::post('/trips/{trip}/students',         [TransportTripController::class, 'storeTripStudent'])->middleware('role:driver,admin,superadmin');
            Route::put('/trips/{trip}/students/{tripStudent}', [TransportTripController::class, 'updateTripStudent'])->middleware('role:driver,admin,superadmin');
            Route::post('/trips/{trip}/students/{tripStudent}/board', [TransportTripController::class, 'boardTripStudent'])->middleware('role:driver,admin,superadmin');
            Route::post('/trips/{trip}/students/{tripStudent}/drop-off', [TransportTripController::class, 'dropOffTripStudent'])->middleware('role:driver,admin,superadmin');
            Route::apiResource('routes', TransportController::class);
            Route::get('/routes/{route}/geofences',     [TransportController::class, 'geofencesForRoute'])->middleware('role:admin,receptionist');
            Route::post('/routes/{route}/geofences',    [TransportController::class, 'storeGeofence'])->middleware('role:admin,receptionist');
            Route::get('/live',                         [TransportController::class, 'live']);
            Route::post('/routes/{route}/assign',       [TransportAssignmentController::class, 'assignStudent'])
                ->middleware('role:admin,receptionist');
            Route::post('/routes/{route}/pickup-status', [TransportAssignmentController::class, 'reportPickupStatus'])->middleware('role:driver');
            Route::get('/vehicles/{id}/telemetry/history', [TransportController::class, 'telemetryHistory']);
            Route::get('/analytics/overview', [TransportController::class, 'transportAnalyticsOverview'])->middleware('role:driver,admin,superadmin');
            Route::get('/analytics/driver-ranking', [TransportController::class, 'transportDriverRanking'])->middleware('role:driver,admin,superadmin');
            Route::get('/analytics/heatmap', [TransportController::class, 'transportHeatMap'])->middleware('role:driver,admin,superadmin');
            Route::get('/analytics/prediction', [TransportController::class, 'transportAnalyticsPrediction'])->middleware('role:driver,admin,superadmin');
            Route::get('/vehicles/{vehicle}/assignments', [TransportFleetController::class, 'vehicleAssignments']);
            Route::post('/vehicles/{vehicle}/assignments', [TransportFleetController::class, 'storeVehicleAssignment']);
            Route::put('/vehicles/{vehicle}/assignments/{assignment}', [TransportFleetController::class, 'updateVehicleAssignment']);
            Route::delete('/vehicles/{vehicle}/assignments/{assignment}', [TransportFleetController::class, 'deleteVehicleAssignment']);
            Route::get('/drivers/{driver}/assignments', [TransportFleetController::class, 'driverAssignments']);
            Route::post('/vehicles/{id}/telemetry', [TransportController::class, 'updateTelemetry']);
            Route::post('/emergency', [TransportController::class, 'emergency']);
        });

        // ── Events ─────────────────────────────────────────────
        Route::apiResource('events', EventController::class);

        // ── Messages ───────────────────────────────────────────
        Route::prefix('messages')->group(function () {
            Route::get('/',         [MessageController::class, 'index']);
            Route::post('/',        [MessageController::class, 'store']);
            Route::get('/{user}',   [MessageController::class, 'show']);
        });

        // ── SMS (Bulk) ──────────────────────────────────────────
        // Admins can send bulk SMS to parents via this endpoint.
        Route::middleware('role:admin')->group(function () {
            Route::post('sms/bulk', [\App\Http\Controllers\Api\Communication\SmsController::class, 'sendBulk']);
            Route::get('sms/logs', [\App\Http\Controllers\Api\Communication\SmsLogController::class, 'index']);
            Route::post('sms/retry/{id}', [\App\Http\Controllers\Api\Communication\SmsLogController::class, 'retry']);
        });

        // ── Analytics ──────────────────────────────────────────
        // Dashboard analytics are available to any authenticated school user.
        Route::prefix('analytics')->group(function () {
            Route::get('/overview',   [AnalyticsController::class, 'overview']);
            Route::get('/library',    [LibraryController::class, 'stats']);
            Route::get('/grades',     [AnalyticsController::class, 'gradePerformance']);
            Route::get('/exams',      [AnalyticsController::class, 'examPerformance']); // <-- Add this
            Route::get('/fees',       [AnalyticsController::class, 'feeAnalytics']);
            Route::get('/attendance', [AnalyticsController::class, 'attendanceTrend']);
        });

        // ── Settings ───────────────────────────────────────────
        Route::middleware('role:admin,superadmin')->prefix('settings')->group(function () {
            Route::get('/',       [\App\Http\Controllers\Api\SettingsController::class, 'index']);
            Route::put('/',       [\App\Http\Controllers\Api\SettingsController::class, 'update']);
            Route::get('/school', [\App\Http\Controllers\Api\SettingsController::class, 'school']);
            Route::put('/school', [\App\Http\Controllers\Api\SettingsController::class, 'updateSchool']);
        });

        Route::prefix('finance')->middleware('role:admin,superadmin,accountant')->group(function () {
        // Phase 2: M-Pesa Reconciliation
            Route::get('reconciliation', [PaymentReconciliationController::class, 'index']);
            Route::post('reconciliation/{item}/resolve', [PaymentReconciliationController::class, 'resolve']);
        });

        // NOTE: M-Pesa webhook routes moved outside the authenticated middleware group below.

        // Parent Portal
        Route::prefix('portal')->middleware(['auth:sanctum', 'role:parent'])->group(function () {
            Route::get('analytics', [ParentPaymentController::class, 'analytics']);
            Route::get('students/{student}/finance', [ParentPaymentController::class, 'studentFinanceSummary']);
            Route::post('payments/mpesa/stk-push', [ParentPaymentController::class, 'initiateStkPush']);
            
            // Transport tracking for parents
            Route::get('students/{student}/transport/trip', [TransportTripController::class, 'parentViewStudentTrip']);
            Route::get('students/{student}/transport/status', [TransportTripController::class, 'parentStudentTransportStatus']);
        });
    });
});

// Fallback
Route::fallback(fn() => response()->json(['success' => false, 'message' => 'Route not found.'], 404));