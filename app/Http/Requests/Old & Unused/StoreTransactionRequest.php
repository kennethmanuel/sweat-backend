<?php

// namespace App\Http\Requests\V1;

// use Illuminate\Foundation\Http\FormRequest;

// class StoreTransactionRequest extends FormRequest
// {
//     /**
//      * Determine if the user is authorized to make this request.
//      *
//      * @return bool
//      */
//     public function authorize()
//     {
//         $user = $this->user();

//         return $user !== null && $user->tokenCan('make_transaction');
//     }

//     /**
//      * Get the validation rules that apply to the request.
//      *
//      * @return array<string, mixed>
//      */
//     public function rules()
//     {
//         return [
//             'userId' => ['required'],
//             'scheduleId' => ['required'],
//         ];
//     }

//     protected function prepareForValidation()
//     {
//         $this->merge([
//             'user_id' => $this->userId,
//             'schedule_id' => $this->scheduleId,
//         ]);
//     }

// }
