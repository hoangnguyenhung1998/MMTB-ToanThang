@once
@inject('dailyWorkflow', 'App\Services\DailyPhotoWorkflowService')
@php $dailySuggestions = $dailyWorkflow->suggestions(); @endphp
@foreach($dailySuggestions as $field => $values)<datalist id="daily-suggestions-{{ $field }}"></datalist>@endforeach
<script>
(() => {
    const values = @json($dailySuggestions);
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g,'d').replace(/Đ/g,'D').toLowerCase();
    document.querySelectorAll('input[name="work_content"], input[name="work_location"]').forEach(input => {
        const list = document.getElementById('daily-suggestions-' + input.name);
        if (!list) return;
        input.setAttribute('list', list.id);
        const refresh = () => {
            list.replaceChildren();
            (values[input.name] || []).filter(value => normalize(value).includes(normalize(input.value))).slice(0, 20).forEach(value => {
                const option = document.createElement('option'); option.value = value; list.append(option);
            });
        };
        input.addEventListener('input', refresh); input.addEventListener('focus', refresh);
    });
})();
</script>
@endonce
