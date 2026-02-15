<?php

use App\Http\Controllers\Api\QuizController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/apiAuth.php';

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('quizzes', QuizController::class);
    Route::put('quizzes/{id}/questions', [QuizController::class, 'updateQuestions']);
    Route::post('quizzes/{id}/publish', [QuizController::class, 'publish']);
});