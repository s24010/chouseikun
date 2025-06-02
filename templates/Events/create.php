<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>イベント作成画面</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <!--<link rel="stylesheet" href="style.css">-->
    <?= $this->Html->css('style') ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
</head>
<body>

<h1>イベント作成</h1>

<!--CakePHP Formヘルパーで開始 -->
<?= $this->Form->create(null, ['url' => ['controller' => 'Events', 'action' => 'create']]) ?>

    <label for="eventName">イベント名（必須）:</label>
    <?= $this->Form->text('event_name', ['id' => 'eventName', 'required' => true]) ?>

    <label for="eventDescription">説明文（任意）:</label>
    <?= $this->Form->textarea('event_description', ['id' => 'eventDescription', 'rows' => 4]) ?>

    <label>日付と時間を選択:</label>
    <div class="datepicker-time-container">
        <div id="datepicker"></div>

        <div class="timepicker">
            <label>時刻指定:</label>
            <select id="hourSelect"></select>
            <select id="minuteSelect"></select>
            <button type="button" id="addCandidate">追加</button>
        </div>
    </div>

    <label for="candidateDates">日程候補日（必須）:</label>
    <?= $this->Form->textarea('event_candidates', [
        'id' => 'candidateDates',
        'rows' => 8,
        'placeholder' => '例: 04/25 19:00～',
        'required' => true
    ]) ?>

    <?= $this->Form->button('イベント作成') ?>
<?= $this->Form->end() ?>

<!--作成済イベント一覧（Controllerから $createdEvents を受け取る） -->
<?php if (!empty($createdEvents)): ?>
    <hr>
    <h2>作成済みイベント一覧</h2>

    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th></th>
                <th>タイトル</th>
                <th>説明</th>
                <!--イベントIDはリンクにして該当イベントの出欠情報に遷移できる-->
                <th>イベントID</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($createdEvents as $event): ?>
            <tr>
                <td>
                    <!-- 削除ボタン -->
                    <?= $this->Form->create(null, [
                        'url' => ['controller' => 'Events', 'action' => 'delete', $event['id']],
                        'style' => 'display:inline'
                    ]) ?>
                        <?= $this->Form->submit('削除', ['confirm' => '本当に削除しますか？' ,
                        'class' => 'button-common button-delete'])
                        ?>
                        
                    <?= $this->Form->end() ?>

                    <!-- 更新ボタン(koushin.phpへ) -->
                    <?= $this->Html->link(
                        '更新',
                        ['controller' => 'Events', 'action' => 'edit', $event['id']], // ★ アクションを 'edit' に変更し、イベントIDを直接渡す
                        ['class' => 'button-common button-update']
                    ) ?>
                </td>
                <td><?= h($event['title'] ?? '') ?></td>
                <td><?= nl2br(h($event['memo'] ?? '')) ?></td>
                <td><?= $this->Html->link(
                    h($event['id']), 
                    ['controller' => 'Attendance', 'action' => 'attend', $event['id']],
                    ['title' => '出欠情報を確認する']
                    )?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>


<!--JavaScript部分-->
<script>
$(function() {
    $("#datepicker").datepicker({ minDate: 0 });

    let hourOptions = '<option value="">--時--</option>';
    for (let i = 0; i < 24; i++) {
        hourOptions += `<option value="${('0' + i).slice(-2)}">${i}時</option>`;
    }
    $("#hourSelect").html(hourOptions);

    let minuteOptions = '<option value="">--分--</option>';
    ["00", "15", "30", "45"].forEach(m => {
        minuteOptions += `<option value="${m}">${m}分</option>`;
    });
    $("#minuteSelect").html(minuteOptions);

    $("#addCandidate").click(function() {
        const dateText = $("#datepicker").datepicker("getDate");
        const hour = $("#hourSelect").val();
        const minute = $("#minuteSelect").val();

        if (!dateText || hour === "" || minute === "") {
            alert("日付と時間を両方選んでください！");
            return;
        }

        const mm = ('0' + (dateText.getMonth() + 1)).slice(-2);
        const dd = ('0' + dateText.getDate()).slice(-2);
        const newLine = `${mm}/${dd} ${hour}:${minute}～`;

        $("#candidateDates").val(function(index, val) {
            return val + (val ? "\n" : "") + newLine;
        });
    });
});
$(function() {
    $('.edit-btn').click(function() {
        const title = $(this).data('title');
        const memo = $(this).data('memo');
        const options = $(this).data('options');

        $('#eventName').val(title);
        $('#eventDescription').val(memo);
        $('#candidateDates').val(options);
    });
});
</script>