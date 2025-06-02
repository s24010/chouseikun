$(function() {
    $("#datepicker").datepicker({
        minDate: 0 // 今日以降だけ選べる
    });

    // 時間セレクト作成
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

    // 候補日追加ボタン
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