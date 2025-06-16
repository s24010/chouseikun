<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>出欠入力</title>
    <style>
        .candidate-table {
            border-collapse: collapse;
            width: 100%;
            text-align: center;
        }

        .candidate-table th, .candidate-table td {
            border: 1px solid #999;
            padding: 12px;
        }

        .candidate-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .mark-cell { /* これは現状のHTMLでは使われていませんが、残しておきます */
            text-align: center;
            vertical-align: middle;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .radio-cell {
            text-align: center;
            vertical-align: middle;
        }
        /* 新規入力に戻るボタン用のスタイル（オプション） */
        .button-clear {
            display: inline-block;
            padding: 8px 16px;
            background-color: #6c757d; /* グレー系の色 */
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin-left: 10px;
            border: none;
            cursor: pointer;
            text-transform: none;
        }
        .button-clear:hover {
            background-color: #5a6268;
            text-transform: none;
        }
        .load-attendance-btn {
         text-transform: none !important;
        }

    </style>
</head>
<body>

<p>
    <?= $this->Html->link(
        'イベント作成ページに戻る',
        ['controller' => 'Events', 'action' => 'create'],
        ['class' => 'button-back', 'style' => 'display:inline-block; padding:8px 16px; background-color:#007bff; color:#fff; text-decoration:none; border-radius:4px;']
    ) ?>
</p>

<h1><?= h($event['title'] ?? '（タイトル不明）') ?></h1>
<p><?= nl2br(h($event['memo'] ?? '')) ?></p>

<hr>

<h2>出欠入力フォーム</h2>

<?= $this->Form->create(null, ['url' => ['controller' => 'Attendance', 'action' => 'submit'], 'id' => 'attendance-form']) ?>

<?= $this->Form->hidden('event_id', ['value' => $event['id']]) ?>
<?= $this->Form->hidden('applicant_id', ['id' => 'applicant-id-field']) ?>

<p>
    名前（必須）:<br>
    <?= $this->Form->text('name', ['required' => true, 'id' => 'name-field']) ?>
</p>

<?php if (!empty($event['candidates'])): ?>
    <h3>日程候補</h3>
    <table class="candidate-table"> <thead>
        <tr>
            <th></th> <?php foreach ($event['candidates'] as $c): ?>
                <th><?= h($c['label']) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>出欠</td>
            <?php foreach ($event['candidates'] as $option): ?>
                <td class="radio-cell"> <?= $this->Form->radio("attendance[{$option['id']}]", [
                        ['value' => '○', 'text' => '○'],
                        ['value' => '×', 'text' => '×']
                    ], ['legend' => false, 'separator' => '&nbsp;&nbsp;']) // label=>false と separator で調整
                    ?>
                </td>
            <?php endforeach; ?>
        </tr>
    </tbody>
</table>

<?php endif; ?>

<p>
    コメント（必須）:<br>
    <?= $this->Form->text('comment', ['id' => 'comment-field']) ?>
</p>

<p>
    <?= $this->Form->button('送信する', ['id' => 'submit-button']) ?>
    <button type="button" id="clear-form-button" class="button-clear" style="display:none;">新規入力に戻る</button>
</p>

<?= $this->Form->end() ?>

<hr>

<h2>出欠一覧</h2>

<?php if (!empty($applicants['applicant_list'])): ?>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>名前</th>
                <?php foreach ($event['candidates'] as $c): ?>
                    <th><?= h($c['label']) ?></th>
                <?php endforeach; ?>
                <th>コメント</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applicants['applicant_list'] as $person): ?>
                <tr>
                    <td>
                        <button type="button" class="load-attendance-btn"
                            data-applicant-id="<?= h($person['id']) ?>" data-name="<?= h($person['name']) ?>"
                            data-comment="<?= h($person['comment'] ?? '') ?>"
                            data-attendance='<?= json_encode($person['attendance_marks'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                            <?= h($person['name']) ?>
                        </button>
                    </td>
                    <?php foreach ($event['candidates'] as $c): ?>
                        <?php
                            $mark = '－'; // デフォルト
                            // $person['available_times'] はコントローラで $reconstructedAvailableTimesForView として整形された配列
                            // [{'time_option_id': ID, 'mark': '○'}, ...] という形式のはず
                            if (isset($person['available_times']) && is_array($person['available_times'])) {
                                foreach ($person['available_times'] as $avail) {
                                    if (isset($avail['time_option_id']) && $avail['time_option_id'] === $c['id']) {
                                        $mark = $avail['mark'];
                                        break;
                                    }
                                }
                            }
                        ?>
                        <td style="text-align:center;"><?= h($mark) ?></td>
                    <?php endforeach; ?>
                    <td><?= h($person['comment'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>まだ出欠が入力されていません。</p>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() { // DOMが読み込まれてから実行

    const attendanceForm = document.getElementById('attendance-form');
    const applicantIdField = document.getElementById('applicant-id-field');
    const nameField = document.getElementById('name-field');
    const commentField = document.getElementById('comment-field');
    const submitButton = document.getElementById('submit-button');
    const clearFormButton = document.getElementById('clear-form-button');

    document.querySelectorAll('.load-attendance-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const applicantId = this.dataset.applicantId;
            const name = this.dataset.name;
            const comment = this.dataset.comment;
            // this.dataset.attendance は '{ "日程ID1": "○", "日程ID2": "×", ... }' のようなJSON文字列を期待
            const attendanceMarks = JSON.parse(this.dataset.attendance);

            // フォームにデータ復元
            applicantIdField.value = applicantId;
            nameField.value = name;
            commentField.value = comment;

            // ラジオボタンの状態を復元
            // まず全ての日程候補のラジオボタンのチェックを外す (オプション: UI的にどちらも未選択で開始したい場合)
            // attendanceForm.querySelectorAll('input[type="radio"][name^="attendance"]').forEach(radio => {
            //     radio.checked = false;
            // });

            // attendanceMarks に基づいて該当するラジオボタンにチェックを入れる
            const candidateOptions = attendanceForm.querySelectorAll('input[type="radio"][name^="attendance"]');
            candidateOptions.forEach(radio => radio.checked = false); // 一旦全クリア

            for (const timeOptionId in attendanceMarks) {
                const mark = attendanceMarks[timeOptionId];
                // `attendance[日程ID]` という name を持ち、かつ `value` が '○' または '×' であるラジオボタンを探す
                const radio = attendanceForm.querySelector(`input[type="radio"][name="attendance[${timeOptionId}]"][value="${mark}"]`);
                if (radio) {
                    radio.checked = true;
                }
            }

            // 送信ボタンのテキストを「更新する」に変更
            if (submitButton) {
                submitButton.textContent = '更新する';
            }
            // 新規入力に戻るボタンを表示 (オプション)
            if (clearFormButton) {
                clearFormButton.style.display = 'inline-block';
            }

            // フォームの入力項目にスクロール
            attendanceForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // (オプション) 新規入力に戻るボタンの処理
    if (clearFormButton) {
        clearFormButton.addEventListener('click', function() {
            attendanceForm.reset(); // フォームの値をリセット (hiddenフィールドもリセットされるか注意)
            applicantIdField.value = ''; // applicant_id を明示的にクリア
            nameField.value = ''; // nameフィールドもクリア (reset()が効かない場合があるため)
            commentField.value = ''; // commentフィールドもクリア

            // ラジオボタンのチェックも全て外す
            attendanceForm.querySelectorAll('input[type="radio"][name^="attendance"]').forEach(radio => {
                radio.checked = false;
            });

            if (submitButton) {
                submitButton.textContent = '送信する'; // ボタンのテキストを「送信する」に戻す
            }
            clearFormButton.style.display = 'none'; // クリアボタンを隠す
            nameField.focus(); // 名前フィールドにフォーカス
        });
    }
});
</script>

</body>
</html>