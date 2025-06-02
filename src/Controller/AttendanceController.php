<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\AppController;
use Cake\Http\Client;
// use Cake\Http\Exception\NotFoundException; // 必要に応じて使用
use Cake\Log\Log;

class AttendanceController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        // FlashコンポーネントはAppControllerでロード済み
    }

    // attend() メソッドは変更なしのため省略 (以前のコードのままです)
    public function attend($eventId = null)
    {
        // ... (以前の attend() メソッドのコード) ...
        // (メッセージ #24 でアップロードされた attend() のコードをそのままお使いください)
        if (!$eventId) {
            $this->Flash->error('イベントIDが指定されていません。');
            return $this->redirect($this->referer() ?? ['controller' => 'Events', 'action' => 'create']);
        }

        $http = new Client(['timeout' => 10]);
        $eventDataForView = null;
        $applicantsDataForView = ['applicant_list' => []]; 

        // 1. イベントの詳細情報の取得
        $eventDetailsApiUrl = "https://chouseikun.onrender.com/event/{$eventId}";
        try {
            $eventDetailsResponse = $http->get($eventDetailsApiUrl);
            if ($eventDetailsResponse->isOk()) {
                $eventInfo = $eventDetailsResponse->getJson();
                Log::debug("EventInfo from API (raw)", ['eventId' => $eventId, 'eventInfo' => (array)$eventInfo]); 
                $eventDataForView = [
                    'id' => $eventInfo['id'] ?? $eventId,
                    'title' => $eventInfo['title'] ?? '（タイトル不明）',
                    'memo' => $eventInfo['memo'] ?? '', 
                    'candidates' => $eventInfo['time_options'] ?? [] 
                ];
                Log::debug("Data prepared for event view (eventDataForView)", ['eventId' => $eventId, 'data' => $eventDataForView]); 
            } else {
                $this->Flash->error('イベント詳細の取得に失敗しました。(APIエラー: ' . $eventDetailsResponse->getStatusCode() . ')');
                return $this->redirect(['controller' => 'Events', 'action' => 'create']);
            }
        } catch (\Exception $e) {
            $this->Flash->error('イベント詳細の取得中に通信エラーが発生しました。');
            return $this->redirect(['controller' => 'Events', 'action' => 'create']);
        }

        // 2. 既存の出欠者情報の取得と加工
        $applicantsApiUrl = "https://chouseikun.onrender.com/event/{$eventId}/applicants";
        try {
            $applicantsResponse = $http->get($applicantsApiUrl);
            if ($applicantsResponse->isOk()) {
                $rawApplicantsList = $applicantsResponse->getJson(); 
                $processedApplicantsList = [];
                Log::debug("Raw Applicants List from API", ['eventId' => $eventId, 'list' => (array)$rawApplicantsList]); 

                if (is_array($rawApplicantsList) && isset($eventDataForView['candidates']) && is_array($eventDataForView['candidates'])) {
                    $allEventCandidateTimeOptionIds = array_map('intval', array_column($eventDataForView['candidates'], 'id'));
                    foreach ($rawApplicantsList as $applicantDataFromApi) { 
                        Log::debug("Processing Applicant Raw Data (from API)", ['eventId' => $eventId, 'applicantData' => (array)$applicantDataFromApi]); 
                        $participantAttendingTimeOptionIdsSet = [];
                        if (isset($applicantDataFromApi['available_times']) && is_array($applicantDataFromApi['available_times'])) {
                            foreach ($applicantDataFromApi['available_times'] as $availableTimeEntry) {
                                if (isset($availableTimeEntry['time_option']['id'])) {
                                    $participantAttendingTimeOptionIdsSet[(int)$availableTimeEntry['time_option']['id']] = true;
                                }
                            }
                        }
                        Log::debug("Participant Attending IDs Set", [ 
                            'eventId' => $eventId,
                            'applicant_name' => $applicantDataFromApi['name'] ?? ($applicantDataFromApi['id'] ?? 'Unknown ID'),
                            'set' => $participantAttendingTimeOptionIdsSet
                        ]);

                        $reconstructedAvailableTimesForView = [];
                        $attendanceMarksForJs = [];
                        if (!empty($allEventCandidateTimeOptionIds)) {
                            foreach ($allEventCandidateTimeOptionIds as $eventCandidateId) {
                                $mark = '×';
                                if (isset($participantAttendingTimeOptionIdsSet[$eventCandidateId])) {
                                    $mark = '○';
                                }
                                $reconstructedAvailableTimesForView[] = [
                                    'time_option_id' => $eventCandidateId,
                                    'mark' => $mark
                                ];
                                $attendanceMarksForJs[$eventCandidateId] = $mark; 
                            }
                        }
                        
                        $processedApplicant = [
                            'id' => $applicantDataFromApi['id'] ?? null,
                            'name' => $applicantDataFromApi['name'] ?? '名前未登録',
                            'comment' => $applicantDataFromApi['memo'] ?? '', 
                            'available_times' => $reconstructedAvailableTimesForView,
                            'attendance_marks' => $attendanceMarksForJs
                        ];
                        $processedApplicantsList[] = $processedApplicant;
                    }
                }
                $applicantsDataForView['applicant_list'] = $processedApplicantsList;
            } else {
                $this->Flash->error('出欠者一覧の取得に失敗しました。(APIエラー: ' . $applicantsResponse->getStatusCode() . ')');
            }
        } catch (\Exception $e) {
            $this->Flash->error('出欠者一覧の取得中に通信エラーが発生しました。');
        }

        $this->set('event', $eventDataForView);
        $this->set('applicants', $applicantsDataForView);
    }


    /**
     * 出欠情報の送信処理 (新規登録または更新)
     */
    public function submit()
    {
        $this->request->allowMethod(['post']);
        $data = $this->request->getData();
        Log::debug('Data received in submit() action:', $data);

        $eventId = $data['event_id'] ?? null;
        $name = trim($data['name'] ?? '');
        $commentFromForm = trim($data['comment'] ?? '');
        $applicantId = $data['applicant_id'] ?? null;
        Log::debug('Applicant ID extracted from form data:', ['applicant_id_in_controller' => $applicantId]);

        // バリデーション
        if (!$eventId || $name === '' || $commentFromForm === '') {
            $this->Flash->error('イベントID、名前、コメントは必須入力です。');
            return $this->redirect($this->referer() ?: ['action' => 'attend', $eventId ?? '']);
        }

        $selectedAvailableTimeIds = [];
        if (!empty($data['attendance']) && is_array($data['attendance'])) {
            foreach ($data['attendance'] as $timeOptionId => $mark) {
                if ($mark === '○') {
                    $selectedAvailableTimeIds[] = (int)$timeOptionId;
                }
            }
        }
        Log::debug("Selected available time IDs for submission:", $selectedAvailableTimeIds);

        // HTTPクライアントのインスタンスはここで作成し、各メソッドに渡すこともできますし、
        // 各メソッド内で個別に作成することもできます。今回は各メソッド内で作成します。

        if ($applicantId) {
            // 更新処理
            return $this->_executeUpdateApplicant((string)$eventId, $applicantId, $selectedAvailableTimeIds, $commentFromForm);
        } else {
            // 新規登録処理
            return $this->_executeCreateApplicant((string)$eventId, $name, $selectedAvailableTimeIds, $commentFromForm);
        }
    }

    /**
     * (プライベートメソッド) 既存の参加者の出欠情報を更新します。
     */
    private function _executeUpdateApplicant(string $eventId, string $applicantId, array $availableTimes, string $memo)
    {
        Log::debug('Executing UPDATE (PATCH) logic for applicant_id: ' . $applicantId);
        
        $payload = [
            'available_times' => $availableTimes,
            'memo' => $memo,
        ];
        $http = new Client(['timeout' => 10]);
        $apiUrl = "https://chouseikun.onrender.com/applicant/edit/{$applicantId}";
        $successMessage = '出欠情報を更新しました。';
        $failureMessagePrefix = '出欠情報の更新に失敗しました。';

        Log::debug("API Request (PATCH)", ['url' => $apiUrl, 'payload' => $payload]);

        try {
            $response = $http->patch($apiUrl, json_encode($payload), [
                'type' => 'json',
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);

            if ($response->isOk() || $response->getStatusCode() === 200) { // 更新成功は通常200 OK
                $this->Flash->success($successMessage);
            } else {
                $apiErrorResponse = null;
                try { $apiErrorResponse = $response->getJson(); } catch (\Exception $e) {}
                $statusCode = $response->getStatusCode();
                $flashErrorMessage = $failureMessagePrefix . "(APIエラー {$statusCode})";
                if (isset($apiErrorResponse['detail'])) {
                    if (is_array($apiErrorResponse['detail'])) {
                        $errorMessages = [];
                        foreach ($apiErrorResponse['detail'] as $validationError) {
                            $location = isset($validationError['loc']) && is_array($validationError['loc']) ? implode(' -> ', array_map('strval', $validationError['loc'])) : '';
                            $message = $validationError['msg'] ?? '詳細不明';
                            $errorMessages[] = trim("{$location}: {$message}");
                        }
                        if (!empty($errorMessages)) {
                            $flashErrorMessage .= "\n" . implode("\n", $errorMessages);
                        }
                    } elseif (is_string($apiErrorResponse['detail'])) {
                        $flashErrorMessage .= "\n" . $apiErrorResponse['detail'];
                    }
                } elseif ($response->getReasonPhrase()) {
                    $flashErrorMessage .= "\n" . $response->getReasonPhrase();
                }
                $this->Flash->error($flashErrorMessage);
                Log::warning("Failed to update attendance. API error {$statusCode}.", [
                    'applicant_id' => $applicantId, 'payload' => $payload, 'response_body' => (string)$response->getBody()
                ]);
            }
        } catch (\Exception $e) {
            $this->Flash->error('出欠情報の更新中に予期せぬ通信エラーが発生しました。');
            Log::error("Connection error while updating attendance for applicant_id {$applicantId}.", [
                'error' => $e->getMessage(), 'payload' => $payload
            ]);
        }
        return $this->redirect(['action' => 'attend', $eventId]);
    }

    /**
     * (プライベートメソッド) 新規に参加者の出欠情報を登録します。
     */
    private function _executeCreateApplicant(string $eventId, string $name, array $availableTimes, string $memo)
    {
        Log::debug('Executing CREATE (POST) logic');

        $payload = [
            'event_id' => (int)$eventId,
            'name' => $name,
            'available_times' => $availableTimes,
            'memo' => $memo,
        ];
        $http = new Client(['timeout' => 10]);
        $apiUrl = 'https://chouseikun.onrender.com/applicant/create';
        $successMessage = '出欠情報を送信しました。';
        $failureMessagePrefix = '出欠情報の送信に失敗しました。';

        Log::debug("API Request (POST)", ['url' => $apiUrl, 'payload' => $payload]);

        try {
            $response = $http->post($apiUrl, json_encode($payload), [
                'type' => 'json',
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);

            if ($response->getStatusCode() === 201 || $response->isOk()) { // 新規作成成功は通常201 Created (または200 OK)
                $this->Flash->success($successMessage);
            } else {
                $apiErrorResponse = null;
                try { $apiErrorResponse = $response->getJson(); } catch (\Exception $e) {}
                $statusCode = $response->getStatusCode();
                $flashErrorMessage = $failureMessagePrefix . "(APIエラー {$statusCode})";
                 if (isset($apiErrorResponse['detail'])) {
                    if (is_array($apiErrorResponse['detail'])) {
                        $errorMessages = [];
                        foreach ($apiErrorResponse['detail'] as $validationError) {
                            $location = isset($validationError['loc']) && is_array($validationError['loc']) ? implode(' -> ', array_map('strval', $validationError['loc'])) : '';
                            $message = $validationError['msg'] ?? '詳細不明';
                            $errorMessages[] = trim("{$location}: {$message}");
                        }
                        if (!empty($errorMessages)) {
                            $flashErrorMessage .= "\n" . implode("\n", $errorMessages);
                        }
                    } elseif (is_string($apiErrorResponse['detail'])) {
                        $flashErrorMessage .= "\n" . $apiErrorResponse['detail'];
                    }
                } elseif ($response->getReasonPhrase()) {
                    $flashErrorMessage .= "\n" . $response->getReasonPhrase();
                }
                $this->Flash->error($flashErrorMessage);
                Log::warning("Failed to create attendance. API error {$statusCode}.", [
                    'payload' => $payload, 'response_body' => (string)$response->getBody()
                ]);
            }
        } catch (\Exception $e) {
            $this->Flash->error('出欠情報の送信中に予期せぬ通信エラーが発生しました。');
            Log::error("Connection error while creating attendance.", [
                'error' => $e->getMessage(), 'payload' => $payload
            ]);
        }
        return $this->redirect(['action' => 'attend', $eventId]);
    }
}