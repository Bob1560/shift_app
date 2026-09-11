function submitAddForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('addScheduleForm'));
    
    fetch(BASE_PATH + '/admin/schedules_api.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeAddModal();
                setTimeout(() => location.reload(), 300);
            } else {
                alert(data.error || 'エラーが発生しました');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            alert('エラーが発生しました: ' + err.message);
        });
}

function deleteSchedule(scheduleId) {
    if (!confirm('このコマを削除してもよろしいですか？')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', '<?= h(generateCsrfToken()) ?>');
    formData.append('action', 'delete');
    formData.append('schedule_id', scheduleId);
    
    fetch(BASE_PATH + '/admin/schedules_api.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                setTimeout(() => location.reload(), 300);
            } else {
                alert(data.error || 'エラーが発生しました');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            alert('エラーが発生しました');
        });
}