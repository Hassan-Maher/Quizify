<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Helpers\ApiResponse;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QuizController extends Controller
{
    public function index(Request $request)
    {
        $query = Quiz::query();

        if ($request->has('instructor_id')) {
            $query->where('instructor_id', $request->instructor_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $quizzes = $query->latest()->get();

        return ApiResponse::sendResponse(200, 'Quizzes retrieved successfully', $quizzes);
    }

    public function store(Request $request)
    {
        // 1. Fix FormData Issue: Decode 'questions' if it arrives as a JSON string
        $input = $request->all();
        if (isset($input['questions']) && is_string($input['questions'])) {
            $input['questions'] = json_decode($input['questions'], true);
        }
        $request->replace($input);
        // 2. Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'grade' => 'required|string|max:255',
            'visibility' => 'required|in:Public,Restricted',
            'time_limit' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'max_attempts' => 'integer|min:1',
            'show_answer_after_question' => 'boolean',
            'cover_image' => 'nullable',

            // Nested Validation for Questions
            'questions' => 'required|array|min:1',
            'questions.*.questionText' => 'required|string',
            'questions.*.type' => 'required|string',
            'questions.*.points' => 'required|numeric',
            'questions.*.correctAnswer' => 'required',
            // Validate options as an array if present
            'questions.*.options' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, 'Validation Error', $validator->errors());
        }
        // 3. Start Transaction
        DB::beginTransaction();
        try {
            // Handle Image Upload
            $coverImagePath = null;
            if ($request->hasFile('cover_image')) {
                $coverImagePath = $request->file('cover_image')->store('quizzes', 'public');
            }
            // Create Quiz
            $quiz = Quiz::create(array_merge(
                $validator->safe()->except(['questions', 'cover_image']),
                [
                    'instructor_id' => auth()->id(),
                    'status' => 'Draft',
                    'cover_image' => $coverImagePath ?? $request->input('cover_image')
                ]
            ));
            // Create Questions
            foreach ($request->questions as $index => $qData) {

                // Prepare Options: ensure it's an array or null
                $options = null;
                if (!empty($qData['options']) && is_array($qData['options'])) {
                    $options = $qData['options']; // Array to be cast by model
                }
                // Create Question directly (without options in the question table)
                $question = $quiz->questions()->create([
                    'question_text' => $qData['questionText'],
                    'type' => $qData['type'],
                    'points' => $qData['points'],
                    'correct_answer' => is_bool($qData['correctAnswer']) ? ($qData['correctAnswer'] ? 'true' : 'false') : $qData['correctAnswer'],
                    'order' => $qData['order'] ?? $index,
                ]);

                // Create Options if they exist
                if (!empty($options)) {
                    foreach ($options as $optIndex => $optText) {
                        $question->options()->create([
                            'option_text' => $optText,
                            'order' => $optIndex
                        ]);
                    }
                }
            }
            DB::commit();
            return ApiResponse::sendResponse(201, 'Quiz created successfully', $quiz->load('questions.options'));
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::sendResponse(500, 'Server Error: ' . $e->getMessage());
        }
    }


    public function show($id)
    {
        $quiz = Quiz::with('questions.options')->find($id);

        if (!$quiz) {
            return ApiResponse::sendResponse(404, 'Quiz not found');
        }

        return ApiResponse::sendResponse(200, 'Quiz retrieved successfully', $quiz);
    }

    public function update(Request $request, $id)
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return ApiResponse::sendResponse(404, 'Quiz not found');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'subject' => 'string|max:255',
            'grade' => 'string|max:255',
            'visibility' => 'in:Public,Restricted',
            'status' => 'in:Draft,Archived,Running,Completed',
            'time_limit' => 'integer|min:1',
            'start_date' => 'date',
            'end_date' => 'date|after:start_date',
            'max_attempts' => 'integer|min:1',
            'show_answer_after_question' => 'boolean',
            'cover_image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, 'Validation Error', $validator->errors());
        }

        $quiz->update($validator->validated());

        return ApiResponse::sendResponse(200, 'Quiz updated successfully', $quiz);
    }

    public function destroy($id)
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return ApiResponse::sendResponse(404, 'Quiz not found');
        }

        $quiz->delete();

        return ApiResponse::sendResponse(200, 'Quiz deleted successfully');
    }

    public function updateQuestions(Request $request, $id)
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return ApiResponse::sendResponse(404, 'Quiz not found');
        }

        $validator = Validator::make($request->all(), [
            'questions' => 'required|array',
            'questions.*.type' => 'required|in:multiple-choice,fill-in-the-blanks,true-false',
            'questions.*.question_text' => 'required|string',
            'questions.*.points' => 'required|integer|min:1',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answer' => 'required|string',
            'questions.*.order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, 'Validation Error', $validator->errors());
        }

        DB::transaction(function () use ($quiz, $request) {
            $quiz->questions()->delete();
            foreach ($request->questions as $questionData) {
                // Ensure options are handled separately
                $options = null;
                if (!empty($questionData['options']) && is_array($questionData['options'])) {
                    $options = $questionData['options'];
                    unset($questionData['options']); // Remove options from question data
                }

                $question = $quiz->questions()->create($questionData);

                // Create Options if they exist
                if (!empty($options)) {
                    foreach ($options as $optIndex => $optText) {
                        $question->options()->create([
                            'option_text' => $optText,
                            'order' => $optIndex
                        ]);
                    }
                }
            }
        });

        return ApiResponse::sendResponse(200, 'Questions updated successfully', $quiz->load('questions.options'));
    }

    public function publish(Request $request, $id)
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return ApiResponse::sendResponse(404, 'Quiz not found');
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Archived,Running',
        ]);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, 'Validation Error', $validator->errors());
        }

        $quiz->update(['status' => $request->status]);

        return ApiResponse::sendResponse(200, 'Quiz status updated successfully', $quiz);
    }
}
