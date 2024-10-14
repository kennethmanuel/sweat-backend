<?php

namespace App\Http\Controllers\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreRatingRequest;
use App\Http\Resources\V1\RatingCollection;
use App\Http\Resources\V1\RatingResource;
use App\Models\Court;
use App\Models\Rating;
use App\Models\RatingPhoto;
use App\Models\Schedule;
use App\Models\Transaction;
use App\Services\V1\RatingQuery;

class RatingController extends Controller
{

    public function index(Request $request) {
        if (auth('sanctum')->check()){
            $userAuth = auth('sanctum')->user();

            $userId = $request->query('userId');
            $ownerId = $request->query('ownerId');

            if ($userAuth->role_id != 3) { //bukan admin
                if ($userAuth->role_id == 1) { //user - penyewa lapangan
                    if ($userId == null) {
                        return response()->json([
                            "status" => 0,
                            "message" => "Must specify user id"
                        ]);
                    }
        
                    if ($userId != $userAuth->id) {
                        return response()->json([
                            "status" => 0,
                            "message" => "Dilarang mengambil data user lain"
                        ]);
                    }
                } else if ($userAuth->role_id == 2) { //pemilik lapangan
                    if ($ownerId == null) {
                        return response()->json([
                            "status" => 0,
                            "message" => "Must specify owner id"
                        ]);
                    }
        
                    if ($ownerId != $userAuth->id) {
                        return response()->json([
                            "status" => 0,
                            "message" => "Dilarang mengambil data owner lain"
                        ]);
                    }
                }
            }

            $filter = new RatingQuery();
            $queryItems = $filter->transform($request); //[['column', 'operator', 'value']]

            $res = Transaction::select('ratings.*');

            if (count($queryItems) > 0) {
                $res = $res->leftJoin('users as u1', 'u1.id', 'ratings.user_id')->leftJoin('courts as c', 'c.id', 'ratings.court_id')->leftJoin('venues as v', 'v.id', 'c.venue_id')->leftJoin('users as u2', 'u2.id', 'v.owner_id')->where($queryItems);
            }

            return new RatingCollection($res->paginate(20)->withQueryString());
        }

        return response()->json([
            'status' => false,
            'message' => "Unauthenticated",
        ], 422);
    }

    public function store(StoreRatingRequest $request) {
        $schedules = Schedule::select('id')->where('court_id', $request->courtId)->get();
        $cleanShcedulesId = [];
        foreach ($schedules as $schedule) {
            array_push($cleanShcedulesId, $schedule->id);
        }
        
        $userId = auth('sanctum')->user()->id;
        if ($userId != $request->userId) {
            return response()->json([
                'status' => false,
                'message' => "User ID Required",
                'data' => null,
            ], 422);
        }
        $transactionCount = Transaction::where('user_id', $userId)->whereIn('id', $cleanShcedulesId)->count();
        $ratingCount = Rating::where('user_id', $userId)->where('court_id', $request->courtId)->count();
        if ($transactionCount <= $ratingCount) {
            return response()->json([
                'status' => false,
                'message' => "Rating <= transaction",
                'data' => null,
            ], 422);
        }

        $res = new RatingResource(Rating::create($request->all()));
        
        $court = Court::where('id', $request->courtId)->first();

        $courtRating = ($court['sum_rating'] + $request->rating) / 2;
        $courtNumberVote = $court['number_of_people'] + 1;

        Court::where('id', $request->courtId)->update([
            'sum_rating' => $courtRating,
            'number_of_people' => $courtNumberVote,
        ]);

        $this->uploadFile($request, $res->id);

        return $res;
    }

    private function uploadFile($request, $ratingId) {
        if ($request->has('files')) {
            $files = $request->file('files');

            $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $allowedVideoExtensions = ['mp4', 'mov'];

            $invalidFile = [];

            foreach($files as $file) {
                $extension = $file->getClientOriginalExtension();

                if (!in_array(strtolower($extension), $allowedImageExtensions) && !in_array(strtolower($extension), $allowedVideoExtensions)) {
                    array_push($invalidFile, $file->getClientOriginalName());
                }
            }

            if(count($invalidFile) == 0) {
                $successFile = [];
                foreach($files as $file) {
                    $fileName = $file->store("private/images/ratings/$ratingId");
                    RatingPhoto::create([
                        'rating_id' => $ratingId,
                        'url' => $fileName,
                    ]);
                    array_push($successFile, $fileName);
                }
                return response()->json([
                    'status' => true,
                    'message' => 'Success',
                    'successFile' => $successFile,
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid File Type',
                    'invalidFileName' => $invalidFile,
                ], 500);
            }

            return true;
        }
    }
}
