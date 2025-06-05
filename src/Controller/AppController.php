<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Client;
use Cake\Http\Cookie\Cookie; // ★ Cookieクラスをインポート
use Cake\Core\Configure; // ★ Configureクラスをインポート
// use Cake\Log\Log; // ★ 必要に応じてログ出力のために追加

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        /*
         * Enable the following component for recommended CakePHP form protection settings.
         * see https://book.cakephp.org/5/en/controllers/components/form-protection.html
         */
        //$this->loadComponent('FormProtection');
    }

    /**
     * クッキーからクリエイターIDを取得するか、なければAPIから新規取得してクッキーに保存します。
     *
     * @return string|null 取得したクリエイターID、またはエラー時はnullを返すことも検討。
     * 現状はエラー時もFlashメッセージで通知し、nullを返します。
     */
    protected function getOrSetCreatorId(): ?string
    {
        $creatorIdCookieName = 'creator_id'; // クッキー名を定数化または変数化しておくと管理しやすい
        $creatorId = $this->request->getCookie($creatorIdCookieName);

        if (!$creatorId) {
            // クッキーが存在しない場合、APIから新しいクリエイターIDを取得
            $http = new Client([
                'timeout' => 10 // APIのタイムアウトを10秒に設定 (例)
            ]);
            $apiBaseUrl = Configure::read('Api.url'); // 設定からAPI URLを読み込む
            $apiUrl = $apiBaseUrl . '/creator/init'; // APIエンドポイント

            try {
                $response = $http->post($apiUrl); // POSTリクエストを送信

                if ($response->isOk()) { // ステータスコードが2xx系か確認
                    $result = $response->getJson(); // JSONレスポンスを配列として取得
                    $newCreatorId = $result['creator_id'] ?? null;

                    if ($newCreatorId) {
                        // ★★★ 修正箇所: Cookieオブジェクトを作成 ★★★
                        $cookie = Cookie::create(
                            $creatorIdCookieName, // Cookie名
                            $newCreatorId,        // Cookieの値
                            [
                                'expires' => strtotime('+1 year'), // 有効期限を1年に設定
                                'path' => '/',                   // サイト全体で有効
                                // 'secure' => true,             // 本番環境(HTTPS)ではtrueを推奨
                                'httponly' => true,              // JavaScriptからのアクセスを禁止
                                'samesite' => Cookie::SAMESITE_LAX // SameSite属性 (CSRF対策) - 定数を使用
                            ]
                        );
                        // 新しいクリエイターIDをクッキーに保存
                        $this->response = $this->response->withCookie($cookie);
                        // ★★★ 修正箇所ここまで ★★★

                        $creatorId = $newCreatorId; // 返す値を更新
                        // $this->Flash->success('ようこそ！クリエイターIDを初期化しました。'); // 必要に応じて通知
                    } else {
                        // APIレスポンスは成功したが、期待するcreator_idが含まれていなかった場合
                        $this->Flash->error('クリエイターIDの初期化に失敗しました。(APIレスポンス形式エラー)');
                        // Log::error("Creator ID API response error: 'creator_id' not found in response from " . $apiUrl);
                        return null; // エラー時はnullを返す
                    }
                } else {
                    // APIがエラーレスポンスを返した場合
                    $statusCode = $response->getStatusCode();
                    $reasonPhrase = $response->getReasonPhrase();
                    $this->Flash->error("クリエイターIDの初期化に失敗しました。(APIエラー {$statusCode}: {$reasonPhrase})");
                    // Log::error("Creator ID API request failed to " . $apiUrl . ": HTTP {$statusCode} - {$reasonPhrase}");
                    return null; // エラー時はnullを返す
                }
            } catch (\Exception $e) {
                // HTTPクライアントの例外 (例: 接続タイムアウト、名前解決不可など)
                $this->Flash->error('クリエイターIDの初期化中に通信エラーが発生しました。しばらくしてから再度お試しください。');
                // Log::error('Creator ID API connection error to ' . $apiUrl . ': ' . $e->getMessage());
                return null; // エラー時はnullを返す
            }
        }
        return $creatorId; // 既存のIDまたは新しく設定されたIDを返す
    }
}
