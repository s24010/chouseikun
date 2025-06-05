<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\AppController; // AppController を継承
use Cake\Http\Client;
use Cake\Http\Exception\NotFoundException; // NotFoundException をインポート
use Cake\Log\Log; // 必要に応じてログ出力のために追加
use Cake\Core\Configure; // env.php から API URL を取得

class EventsController extends AppController // 親クラスを AppController に変更
{
    public function initialize(): void
    {
        parent::initialize(); // 親のinitializeを呼び出す
        // FlashコンポーネントはAppControllerでロードされるため、ここでの再ロードは不要
    }

    /**
     * イベント作成フォームの表示、イベント作成処理、
     * および現在のクリエイターが作成したイベントの一覧表示を行います。
     */
    public function create()
    {
        $creatorId = $this->getOrSetCreatorId(); // AppControllerからクリエイターIDを取得

        if (!$creatorId) {
            $this->set('createdEvents', []);
            $this->set('errors', ['クリエイター情報の初期化に失敗したため、イベントの作成や表示ができません。']);
            return;
        }

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            $title = trim($data['event_name'] ?? '');
            $memo = trim($data['event_description'] ?? '');
            $candidateRaw = trim($data['event_candidates'] ?? '');

            $timeOptions = [];
            if (!empty($candidateRaw)) {
                $timeOptions = array_filter(array_map('trim', preg_split('/\R/', $candidateRaw)));
            }

            $timeOptionsFormatted = array_map(function($label) {
                return ['label' => $label];
            }, $timeOptions);

            $errors = [];
            if ($title === '') {
                $errors[] = 'イベント名は必須入力です。';
            }
            if (count($timeOptionsFormatted) === 0) {
                $errors[] = '候補日程は少なくとも1つ入力してください。';
            }

            if (!empty($errors)) {
                $this->Flash->error('入力内容にエラーがあります。内容をご確認ください。');
                $this->set('errors', $errors);
                $this->_fetchAndSetCreatedEvents($creatorId);
                $this->set('submittedData', $data); // ビューで入力値を復元するために渡す
                return; // ビューを表示 (create.php)
            }

            $payload = [
                'title' => $title,
                'memo' => $memo,
                'user' => $creatorId,
                'time_options' => $timeOptionsFormatted,
            ];

            $http = new Client(['timeout' => 10]);
            $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
            $apiUrl = $apiBaseUrl . '/event/create';

            try {
                $response = $http->post($apiUrl, json_encode($payload), [
                    'type' => 'json',
                    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json']
                ]);

                if ($response->getStatusCode() === 201) {
                    $result = $response->getJson();
                    $eventId = $result['id'] ?? null;

                    if ($eventId) {
                        $this->Flash->success('イベントが正常に作成されました。続けて出欠情報を入力してください。');
                        return $this->redirect(['controller' => 'Attendance', 'action' => 'attend', $eventId]);
                    } else {
                        $this->Flash->error('イベント作成には成功しましたが、イベントIDの取得に失敗しました。');
                        //log::error("Event created, but 'id' not found in API response from " . $apiUrl . ". Response: " . (string)$response->getBody());
                    }
                } else {
                    $this->_handleApiErrorResponse($response, 'イベント作成');
                }
            } catch (\Exception $e) {
                $this->Flash->error('イベント作成中に予期せぬ通信エラーが発生しました。');
                //log::error('Event creation API connection error to ' . $apiUrl . ': ' . $e->getMessage());
            }
            $this->_fetchAndSetCreatedEvents($creatorId);
            $this->set('submittedData', $data);

        } else { // GETリクエスト
            $this->_fetchAndSetCreatedEvents($creatorId);
        }
    }

    /**
     * イベント編集フォームを表示します。
     */
    public function edit($eventId = null)
    {
        if (!$eventId) {
            $this->Flash->error('編集するイベントのIDが指定されていません。');
            return $this->redirect(['action' => 'create']);
        }

        $creatorId = $this->getOrSetCreatorId();
        if (!$creatorId) {
            $this->Flash->error('クリエイター情報を特定できませんでした。');
            return $this->redirect(['action' => 'create']);
        }

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/{$eventId}";

        try {
            $response = $http->get($apiUrl);
            if ($response->isOk()) {
                $event = $response->getJson();
                if (($event['user'] ?? null) !== $creatorId) {
                    $this->Flash->error('このイベントを編集する権限がありません。');
                    return $this->redirect(['action' => 'create']);
                }
                $this->set('event', $event);
                $this->render('koushin'); // koushin.php をビューとして使用
            } else {
                $this->Flash->error('編集対象のイベント情報の取得に失敗しました。(APIエラー: ' . $response->getStatusCode() . ')');
                //log::error("Failed to fetch event for editing (ID: {$eventId}). API error {$response->getStatusCode()} from {$apiUrl}. Body: " . (string)$response->getBody());
                return $this->redirect(['action' => 'create']);
            }
        } catch (\Exception $e) {
            $this->Flash->error('イベント情報取得中に通信エラーが発生しました。');
            //Log::error("Connection error while fetching event for editing (ID: {$eventId}) from {$apiUrl}: " . $e->getMessage());
            return $this->redirect(['action' => 'create']);
        }
    }


    /**
     * イベント情報を更新します。
     */
    public function update()
    {
        $this->request->allowMethod(['post', 'put']);
        $creatorId = $this->getOrSetCreatorId();

        if (!$creatorId) {
            $this->Flash->error('クリエイター情報を特定できませんでした。操作を続行できません。');
            return $this->redirect(['action' => 'create']);
        }

        $data = $this->request->getData();
        $eventId = $data['event_id'] ?? null;

        if (!$eventId) {
            $this->Flash->error('更新対象のイベントIDが指定されていません。');
            return $this->redirect(['action' => 'create']);
        }

        // ここで、更新しようとしているイベントが本当にこのクリエイターのものか、
        // APIから再度取得してユーザーIDを比較する所有者確認を行うのがより安全です。
        // $eventDetails = $this->_getEventDetailsFromServer($eventId);
        // if (!$eventDetails || ($eventDetails['user'] ?? null) !== $creatorId) {
        //     $this->Flash->error('このイベントを更新する権限がありません。');
        //     return $this->redirect(['action' => 'create']);
        // }


        $title = trim($data['event_name'] ?? '');
        $memo = trim($data['event_description'] ?? '');
        $candidateRaw = trim($data['event_candidates'] ?? '');

        $timeOptions = [];
        if (!empty($candidateRaw)) {
            $timeOptions = array_filter(array_map('trim', preg_split('/\R/', $candidateRaw)));
        }

        $timeOptionsFormatted = array_map(function($label) {
            return ['label' => $label];
        }, $timeOptions);

        $errors = [];
        if ($title === '') {
            $errors[] = 'イベント名は必須入力です。';
        }
        if (count($timeOptionsFormatted) === 0) {
            $errors[] = '候補日程は少なくとも1つ入力してください。';
        }

        if (!empty($errors)) {
            $this->Flash->error('入力内容にエラーがあります。');
            // エラー時は編集フォームにリダイレクトし、エラー内容を表示
            // $this->set('errors', $errors); // editアクション側でFlashを使うか、ここでセットするか
            // $this->set('event', $data); // 入力値を保持して表示する場合
            return $this->redirect(['action' => 'edit', $eventId]);
        }

        $payload = [
            'title' => $title,
            'memo' => $memo,
            'user' => $creatorId,
            'time_options' => $timeOptionsFormatted,
        ];

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/{$eventId}";

        try {
            $response = $http->put($apiUrl, json_encode($payload), [
                'type' => 'json',
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json']
            ]);

            if ($response->isOk()) {
                $this->Flash->success('イベント情報を更新しました。');
                return $this->redirect(['controller' => 'Attendance', 'action' => 'attend', $eventId]);
            } else {
                $this->_handleApiErrorResponse($response, 'イベント更新');
                return $this->redirect(['action' => 'edit', $eventId]);
            }
        } catch (\Exception $e) {
            $this->Flash->error('イベント更新中に予期せぬ通信エラーが発生しました。');
            //log::error("Event update API connection error for event {$eventId}: " . $e->getMessage());
            return $this->redirect(['action' => 'edit', $eventId]);
        }
    }


    /**
     * 指定されたクリエイターIDの作成済みイベントを取得し、ビューにセットします。
     */
    private function _fetchAndSetCreatedEvents(?string $creatorId)
    {
        if (!$creatorId) {
            $this->set('createdEvents', []);
            return;
        }

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/by-user/{$creatorId}";

        try {
            $response = $http->get($apiUrl);
            if ($response->isOk()) {
                $result = $response->getJson();
                $this->set('createdEvents', $result['event_list'] ?? []);
            } else {
                $this->set('createdEvents', []);
                //log::warning("Failed to fetch created events for creator {$creatorId}. API error {$response->getStatusCode()} from {$apiUrl}. Body: " . (string)$response->getBody());
            }
        } catch (\Exception $e) {
            $this->set('createdEvents', []);
            //log::error("Connection error while fetching created events for creator {$creatorId} from {$apiUrl}: " . $e->getMessage());
        }
    }

    /**
     * イベント削除処理
     */
    public function delete($eventId = null)
    {
        $this->request->allowMethod(['post', 'delete']); // フォームからのPOSTリクエストを許可
        $creatorId = $this->getOrSetCreatorId();

        if (!$creatorId) {
            $this->Flash->error('クリエイター情報を特定できませんでした。操作を続行できません。');
            return $this->redirect(['action' => 'create']);
        }
        if (!$eventId) {
            $this->Flash->error('削除するイベントのIDが指定されていません。');
            return $this->redirect(['action' => 'create']);
        }

        // ★★★ 所有者確認ロジックの追加 (推奨) ★★★
        $eventDetails = $this->_getEventDetailsFromServer($eventId); // イベント詳細を取得するヘルパーメソッド(後述)
        if (!$eventDetails) {
            $this->Flash->error('削除対象のイベントが見つかりませんでした。');
            return $this->redirect(['action' => 'create']);
        }
        if (($eventDetails['user'] ?? null) !== $creatorId) {
            $this->Flash->error('このイベントを削除する権限がありません。');
            return $this->redirect(['action' => 'create']);
        }
        // ★★★ 所有者確認ここまで ★★★

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/{$eventId}";

        try {
            // API仕様: DELETE /event/{event_id}
            $response = $http->delete($apiUrl);

            if ($response->isOk()) { // 通常、削除成功は200 OK (APIのレスポンススキーマ ResponseEvent を返す)
                $this->Flash->success('イベントが正常に削除されました。');
            } else {
                // APIがエラーを返した場合 (例: 404 Not Found, 403 Forbidden など)
                $this->_handleApiErrorResponse($response, 'イベント削除');
            }
        } catch (\Exception $e) {
            // ネットワークエラーなど、API通信自体に失敗した場合
            $this->Flash->error('イベント削除中に予期せぬ通信エラーが発生しました。');
            //log::error("Event deletion API connection error for event {$eventId}: " . $e->getMessage());
        }

        return $this->redirect(['action' => 'create']); // 処理後、イベント作成/一覧ページに戻る
    }


    /**
     * APIエラーレスポンスを処理し、Flashメッセージに表示します。
     * @param \Cake\Http\Client\Response $response APIレスポンスオブジェクト
     * @param string $actionDescription 実行しようとしたアクションの説明（例: "イベント作成"）
     */
    private function _handleApiErrorResponse(\Cake\Http\Client\Response $response, string $actionDescription): void
    {
        $apiErrorResponse = null;
        try {
            $apiErrorResponse = $response->getJson();
        } catch (\Exception $e) {
            // JSONデコードに失敗した場合など
        }

        $statusCode = $response->getStatusCode();
        $flashErrorMessage = "{$actionDescription}に失敗しました。(APIエラー {$statusCode})";

        if (isset($apiErrorResponse['detail'])) {
            if (is_array($apiErrorResponse['detail'])) {
                $errorMessages = [];
                foreach ($apiErrorResponse['detail'] as $validationError) {
                    $location = '';
                    if (isset($validationError['loc']) && is_array($validationError['loc'])) {
                        $locationParts = array_map('strval', $validationError['loc']);
                        $location = implode(' -> ', $locationParts);
                    }
                    $message = $validationError['msg'] ?? '詳細不明のエラー';
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
        $currentCreatorId = $this->getOrSetCreatorId(); // エラーログ用に取得
        //log::warning("API error during '{$actionDescription}' for creator {$currentCreatorId}: HTTP {$statusCode}. Response: " . (string)$response->getBody());
    }


    // 以下の byUser と applicants アクションは、このコントローラの責務として適切か、
    // アプリケーションの規模や設計に応じて再検討してください。
    public function byUser($userIdFromUrl = null)
    {
        $currentCreatorId = $this->getOrSetCreatorId();
        $userIdToFetch = $userIdFromUrl ?? $currentCreatorId;

        if (!$userIdToFetch) {
            $this->Flash->error('対象のユーザーIDを特定できませんでした。');
            $this->set('userEvents', []);
            $this->set('fetchedUserId', '');
            return;
        }

        $this->set('fetchedUserId', $userIdToFetch);

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/by-user/{$userIdToFetch}";
        try {
            $response = $http->get($apiUrl);
            if ($response->isOk()) {
                $data = $response->getJson();
                $this->set('userEvents', $data['event_list'] ?? []);
            } else {
                $this->Flash->error("指定されたユーザー ({$userIdToFetch}) のイベント取得に失敗しました。(APIエラー {$response->getStatusCode()})");
                $this->set('userEvents', []);
            }
        } catch (\Exception $e) {
            $this->Flash->error("ユーザー ({$userIdToFetch}) のイベント取得中に通信エラーが発生しました。");
            $this->set('userEvents', []);
            //log::error("Error fetching events for user {$userIdToFetch} from {$apiUrl}: " . $e->getMessage());
        }
    }

    public function applicants($eventId = null)
    {
        if (!$eventId) {
            $this->Flash->error('イベントIDが指定されていません。');
            return $this->redirect($this->referer() ?? ['action' => 'create']);
        }

        $http = new Client(['timeout' => 10]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/{$eventId}/applicants";
        try {
            $response = $http->get($apiUrl);
            if ($response->isOk()) {
                $applicants = $response->getJson();
                $this->set('applicants', $applicants);
                $this->set('eventIdForView', $eventId);
            } else {
                $this->Flash->error("イベントID ({$eventId}) の出欠者情報の取得に失敗しました。(APIエラー {$response->getStatusCode()})");
                $this->set('applicants', []);
            }
        } catch (\Exception $e) {
            $this->Flash->error("イベントID ({$eventId}) の出欠者情報取得中に通信エラーが発生しました。");
            $this->set('applicants', []);
            //log::error("Error fetching applicants for event {$eventId} from {$apiUrl}: " . $e->getMessage());
        }
    }

    /**
     * (プライベートヘルパーメソッド) 指定されたイベントIDの詳細をAPIから取得します。
     * 所有者確認などに使用します。
     * @param string $eventId イベントID
     * @return array|null イベント情報、または取得失敗時はnull
     */
    private function _getEventDetailsFromServer(string $eventId): ?array
    {
        $http = new Client(['timeout' => 5]);
        $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
        $apiUrl = $apiBaseUrl . "/event/{$eventId}";
        try {
            $response = $http->get($apiUrl);
            if ($response->isOk()) {
                return $response->getJson();
            }
            //log::warning("Failed to fetch event details from server for ID {$eventId}. Status: " . $response->getStatusCode());
        } catch (\Exception $e) {
            //log::error("Exception while fetching event details from server for ID {$eventId}: " . $e->getMessage());
        }
        return null;
    }
}
