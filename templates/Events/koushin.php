<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>イベント更新</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <?= $this->Html->css('style') ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
</head>
<body>

<h1>イベント更新</h1>

<?= $this->Form->create(null, ['url' => ['controller' => 'Events', 'action' => 'update']]) ?>

<!-- 更新対象イベントID（hidden） -->
<?= $this->Form->hidden('event_id', ['value' => $event['id']]) ?>

<p>
    <label for="eventName">イベント名（必須）:</label><br>
    <?= $this->Form->text('event_name', [
        'id' => 'eventName',
        'required' => true,
        'value' => h($event['title'] ?? '')
    ]) ?>
</p>

<p>
    <label for="eventDescription">説明文（任意）:</label><br>
    <?= $this->Form->textarea('event_description', [
        'id' => 'eventDescription',
        'rows' => 4,
        'value' => h($event['memo'] ?? '')
    ]) ?>
</p>

<p>
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
</p>

<p>
    <label for="candidateDates">日程候補日（必須）:</label><br>
    <?= $this->Form->textarea('event_candidates', [
        'id' => 'candidateDates',
        'rows' => 8,
        'placeholder' => '例: 04/25 19:00～',
        'required' => true,
        'value' => implode("\n", array_column($event['time_options'] ?? [], 'label'))
    ]) ?>
</p>

<p><?= $this->Form->button('イベントを更新') ?></p>

<?= $this->Form->end() ?>

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
</script>
