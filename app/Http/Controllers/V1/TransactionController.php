<?php

namespace App\Http\Controllers\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreTransactionRequest;
use App\Http\Requests\V1\UpdateTransactionRequest;
use App\Http\Resources\V1\TransactionCollection;
use App\Http\Resources\V1\TransactionResource;
use App\Models\CourtPrice;
use App\Models\Fee;
use App\Models\Schedule;
use App\Models\Transaction;
use App\Models\TransactionScheduleDetail;
use App\Models\TransactionStatus;
use App\Models\User;
use App\Services\V1\TransactionQuery;
use DateInterval;
use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TransactionController extends Controller
{
    public function index(Request $request) {
        if (auth('sanctum')->check()){
            $userIdAuth = auth('sanctum')->user()->id;
            $userId = $request->query('user_id')["eq"];

            $filter = new TransactionQuery();
            $queryItems = $filter->transform($request); //[['column', 'operator', 'value']]

            $res = Transaction::select('transactions.id', 'transactions.external_id', 'transactions.order_id', 'transactions.user_id', 'transactions.schedule_id', 'transactions.court_id', 'transactions.reason', 'transactions.transaction_status_id', 'transactions.created_at', 'transactions.updated_at')->with('schedule')->with('court')->with('transactionStatus');

            if (count($queryItems) > 0) {
                $res->leftJoin('transaction_statuses', 'transaction_statuses.id', '=', 'transactions.transaction_status_id')->where($queryItems);
            }
            
            if (auth('sanctum')->user()->role_id == 1) {
                return new TransactionCollection($res->paginate(10)->withQueryString());
            } if ($userId && $userIdAuth == $userId) {
                $res->where('transactions.user_id', $userId);
                return new TransactionCollection($res->paginate(10)->withQueryString());
            }
        }

        return response()->json([
            'status' => false,
            'message' => "User ID Required",
            'data' => null,
        ], 422);
    }

    public function show(Transaction $transaction) {
        if (auth('sanctum')->check()){
            $userIdAuth = auth('sanctum')->user()->id;

            if (auth('sanctum')->user()->role_id == 1) {
                return new TransactionResource($transaction->loadMissing('schedule')->loadMissing('court')->loadMissing('transactionStatus'));
            } else if ($transaction->user_id && $userIdAuth == $transaction->user_id) {
                return new TransactionResource($transaction->loadMissing('schedule')->loadMissing('court')->loadMissing('transactionStatus'));
            }
        }
        
        return response()->json([
            'status' => false,
            'message' => "Unauthenticated",
            'data' => null,
        ], 422);
    }

    private function xenditPayment($externalId, $user, $schedule, $fee) {
        //XENDIT HERE
        $xenditParams = [
            'external_id' => $externalId,
            'payer_email' => $user->email,
            'description' => "Pembayaran Penyewaan Lapangan pada " . $schedule->date,
            'amount' => $schedule->price + $fee,
            'success_redirect_url' => url(env('APP_HOST') . "/payment-success"),
            'failed_redirect_url' => url(env('APP_HOST') . "/payment-failed"),
            'invoice_duration' => 18000, // 5 hours
            'currency' => "IDR",
            "customer" => [
                "given_names" => $user->name,
                "surname" => $user->name,
                "email" => $user->email,
                "mobile_number" => "+62" . $user->phone_number,
                // "addresses" => [
                //     [
                //         "city" => "Jakarta Selatan",
                //         "country" => "Indonesia",
                //         "postal_code" => "12345",
                //         "state" => "Daerah Khusus Ibukota Jakarta",
                //         "street_line1" => "Jalan Makan",
                //         "street_line2" => "Kecamatan Kebayoran Baru"
                //     ]
                // ]
            ],
            "customer_notification_preference" => [
                "invoice_created" => [
                    // "whatsapp",
                    "email",
                ],
                "invoice_reminder" => [
                    // "whatsapp",
                    "email",
                ],
                "invoice_paid" => [
                    "whatsapp",
                    "email",
                ],
                "invoice_expired" => [
                    // "whatsapp",
                    "email",
                ]
            ],
            "fees" => [
                [
                    "type" => "Jasa Aplikasi",
                    "value" => $fee,
                ]
            ]
        ];

        $response = Http::withHeaders([
            "Authorization" =>"Basic ".base64_encode(env("XENDIT_API_KEY", "") .':' . '')
        ])->
        post('https://api.xendit.co/v2/invoices/', $xenditParams);

        return $response;
    }

    public function store(StoreTransactionRequest $request) {
        
        if (isset($request->userId) && isset($request->scheduleId)) {
            
            if (!is_array($request->scheduleId)) {
                return response()->json([
                    "status" => false,
                    "message" => "schedule ID must be array of integer",
                ], 500);
            }

            $totalPrice = 0;
            foreach ($request->scheduleId as $scheduleId) {
                $schedule = Schedule::where('id', $scheduleId)->first();
                
                $courtPrice = CourtPrice::where('court_id', $schedule->court_id)->where('duration_in_hour', $schedule->interval)->where('is_member_price', 1)->first()['price'];
                $totalPrice += $courtPrice;
                
                if ($schedule->availability == 0 || $schedule->status == 0) {
                    return response()->json([
                        "status" => false,
                        "message" => "Lapangan pada tanggal " . $this->tgl_indo($schedule->date) . " pukul " . $schedule->time_start . " hingga " . $schedule->time_finish . " sedang tidak tersedia",
                    ], 500);
                }
            }

            $user = User::where('id', $request->userId)->first();
            $fee = Fee::where('name', 'app_admin')->first()->amount_rp;
            $externalId = "DAILY_" . time();

            // $xenditResponse = $this->xenditPayment($externalId, $user, $schedule, $fee);
            // if (!$xenditResponse->successful()) {
            //     return response()->json([
            //         "status" => false,
            //         "message" => "Payment failed because system error",
            //     ], 500);
            // }

            $transaction = Transaction::create([
                "external_id" => $externalId,
                "user_id" => $request->userId,
                "transaction_status_id" => 5, // menunggu konfirmasi / pembayaran
                "amount_rp" => $totalPrice + $fee,
                "schedule_id" => $request->scheduleId[0],

                // // XENDIT HERE
                // "checkout_link" => $response->collect()['invoice_url'],
                // "invoice_id" => $response->collect()['id'],
                // // UNTIL HERE

            ]);
            foreach($request->scheduleId as $scheduleId) {
                TransactionScheduleDetail::create([
                    "schedule_id" => $scheduleId,
                    "transaction_id" => $transaction->id,
                ]);
                Schedule::where('id', $scheduleId)->update([
                    'availability' => 0,
                ]);
            }
        } else {
            return response()->json([
                "status" => false,
                "message" => "Required Parameter: user ID, schedule IDs"
            ], 500);
        }

        return response()->json([
            "status" => true,
            "message" => "Sukses melakukan pemesanan. Silakan lanjutkan pembayaran"
        ]);

    }

    private function getAvailMemberSchedules($request, $courtId) {
        $query = "SELECT *, DAYOFWEEK(date) AS day_of_week FROM `schedules` WHERE availability = 1 AND status = 1 AND court_id = $courtId AND date >= '$request->dateStart' HAVING ";
        $query .= "(";
        foreach ($request->schedules as $i=>$requestSchedule) {
            $query .= "(day_of_week = " . $requestSchedule['dayOfWeek'] . " AND ";
            
            $query2 = "";
            foreach($requestSchedule['scheduleIds'] as $j => $scheduleId) {

                $schedule = Schedule::where('id', $scheduleId)->first();
                
                $query2 .= "date in (SELECT DISTINCT date FROM `schedules` WHERE time_start = '$schedule->time_start' AND time_finish = '$schedule->time_finish' AND availability = 1 AND status = 1)";

                if ($j != count($requestSchedule['scheduleIds']) - 1) {
                    $query2 .= " AND ";
                }
            }

            $query .= $query2;

            if ($i < count($requestSchedule) - 1) {
                $query .= ") OR ";
            } else {
                $query .= ")";
            }
        }
        $query .= ")";
        $query .= " ORDER BY date";

        return DB::select(DB::raw($query));
    }

    private function getUnAvailMemberSchedules($request, $courtId) {
        $query = "SELECT DISTINCT court_id, date, DAYOFWEEK(date) AS day_of_week FROM `schedules` WHERE court_id = $courtId AND date >= '$request->dateStart' HAVING ";
        $query .= "(";
        foreach ($request->schedules as $i=>$requestSchedule) {
            $query .= "(day_of_week = " . $requestSchedule['dayOfWeek'] . " AND ";
            
            $query2 = "(";
            foreach($requestSchedule['scheduleIds'] as $j => $scheduleId) {

                $schedule = Schedule::where('id', $scheduleId)->first();
                
                $query2 .= "date NOT IN (SELECT DISTINCT date FROM `schedules` WHERE time_start = '$schedule->time_start' AND time_finish = '$schedule->time_finish' AND availability = 1 AND status = 1)";

                if ($j != count($requestSchedule['scheduleIds']) - 1) {
                    $query2 .= " OR ";
                }
            }

            $query2 .= ")";

            $query .= $query2;

            if ($i < count($requestSchedule) - 1) {
                $query .= ") OR ";
            } else {
                $query .= ")";
            }
        }
        $query .= ")";
        $query .= " ORDER BY date";

        return DB::select(DB::raw($query));
    }

    private function tgl_indo($tanggal)
    {
        $bulan = array(
            1 =>   'Januari',
            'Februari',
            'Maret',
            'April',
            'Mei',
            'Juni',
            'Juli',
            'Agustus',
            'September',
            'Oktober',
            'November',
            'Desember'
        );
        $pecahkan = explode('-', $tanggal);
        return $pecahkan[2] . ' ' . $bulan[(int)$pecahkan[1]] . ' ' . $pecahkan[0];
    }

    public function bulkStore(Request $request) { //UNTUK DAFTAR MEMBER
        if (isset($request->userId) && isset($request->schedules) && isset($request->dateStart) && isset($request->month)) {
            // Month = berapa bulan
            // Kalau month = 1 --> (1)*4 -> supaya dapat 4 minggu

            if (!is_array($request->schedules)) {
                return response()->json([
                    "status" => false,
                    "message" => "Schedule ID must be array of integer",
                ], 500);
            }

            $schedule1 = Schedule::where('id', $request->schedules[0]['scheduleIds'][0])->first();
            $courtId = $schedule1->court_id;

            $unavailableSchedule = $this->getUnavailMemberSchedules($request, $courtId);
            $unavailableScheduleDate = "";
            
            if (count($unavailableSchedule) > 0) {
                foreach ($unavailableSchedule as $k => $sched) {
                    $unavailableScheduleDate .= $this->tgl_indo($sched->date);
                    if ($k != count($unavailableSchedule) - 1) {
                        $unavailableScheduleDate .= ", ";
                    }
                }

                return response()->json([
                    'status' => false,
                    'message' => "Jadwal pada " . $unavailableScheduleDate . " sedang tidak tersedia"
                ]);
            }


            $availableSchedule = $this->getAvailMemberSchedules($request, $courtId);
            $totalPrice = 0;

            return $availableSchedule[0]->id;

            foreach ($availableSchedule as $sched) {
                $totalPrice += CourtPrice::where('court_id', $sched->court_id)->where('duration_in_hour', $sched->interval)->where('is_member_price', 1)->first()->price;
            }

            $user = User::where('id', $request->userId)->first();
            $fee = Fee::where('name', 'app_admin')->first()->amount_rp;
            $externalId = "MEMBER_" . time();

            // $xenditResponse = $this->xenditPayment($externalId, $user, $schedule, $fee);
            // if (!$xenditResponse->successful()) {
            //     return response()->json([
            //         "status" => false,
            //         "message" => "Payment failed because system error",
            //     ], 500);
            // }
            
            $transaction = Transaction::create([
                "external_id" => $externalId,
                "user_id" => $request->userId,
                "transaction_status_id" => 5, // menunggu konfirmasi / pembayaran
                "amount_rp" => $totalPrice + $fee,
                "schedule_id" => $availableSchedule[0]->id,

                // // XENDIT HERE
                // "checkout_link" => $response->collect()['invoice_url'],
                // "invoice_id" => $response->collect()['id'],
                // // UNTIL HERE

            ]);
            foreach($availableSchedule as $sched) {
                TransactionScheduleDetail::create([
                    "schedule_id" => $sched->id,
                    "transaction_id" => $transaction->id,
                ]);
                Schedule::where('id', $sched->id)->update([
                    'availability' => 0,
                ]);
            }
        } else {
            return response()->json([
                "status" => false,
                "message" => "Required Parameter: user ID, schedule IDs, date start, and month"
            ], 500);
        }

        return response()->json([
            "status" => true,
            "message" => "Sukses melakukan pemesanan. Silakan lanjutkan pembayaran"
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction) {
        $transaction->update($request->all());

        if (auth('sanctum')->check()){
            $userIdAuth = auth('sanctum')->user();
            if ($userIdAuth->id == $transaction->user_id) { 
                //check
                $dt = new DateTime();
                $dt->add(new DateInterval('P1D'));
                if (strtotime($transaction->schedule->date . " " . $transaction->schedule->time_start) > strtotime($dt->format('Y-m-d H:i:s'))) {
                    if ($request->transactionStatusId == 2 || $request->transactionStatusId == 3 || $request->transactionStatusId == 4) {
                        Schedule::where('id', $transaction->scheduleId)->update([
                            'availability' => '1'
                        ]);
                    }
                    return 1; 
                };

                return response()->json([
                    "status" => false,
                    "message" => "Pembatalan ditolak karena pembatalan harus lebih dari 24 jam sebelumnya"
                ]);
            } elseif ($userIdAuth->roleId == 4) { // 4 == Admin -- tidak ada cek.
                if ($request->transactionStatusId == 2 || $request->transactionStatusId == 3 || $request->transactionStatusId == 4) {
                    Schedule::where('id', $transaction->scheduleId)->update([
                        'availability' => '1'
                    ]);
                }
                return 1;
            } 

            return response()->json([
                "status" => false,
                "message" => "Unauthorized"
            ], 403);

        } else {
            return response()->json([
                "status" => false,
                "message" => "Unauthenticated"
            ], 403);
        }
    }

    public function filterOptions() {
        $transactionStatuses = TransactionStatus::all();
        $res = [];
        foreach($transactionStatuses as $i=>$transactionStatus) {
            $res[] = $transactionStatus['status'];
        }

        return response()->json($res);
    }
}
