document.addEventListener('DOMContentLoaded', function () {
    function getWrapperFromInput(input) {
        return input ? input.closest('.password-wrapper') : null;
    }

    function setWrapperState(wrapper) {
        if (!wrapper) return;
        const input = wrapper.querySelector('.password-input');
        const btn = wrapper.querySelector('.toggle-password');
        if (!input || !btn) return;

        const hasValue = (input.value || '').length > 0;
        wrapper.classList.toggle('has-value', hasValue);

        if (!hasValue) {
            input.type = 'password';
            wrapper.classList.remove('is-visible');
        }
    }

    function syncAll() {
        document.querySelectorAll('.password-wrapper').forEach(setWrapperState);
    }

    syncAll();
    window.addEventListener('load', syncAll);
    setTimeout(syncAll, 50);

    document.addEventListener('input', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (!target.classList.contains('password-input')) return;
        setWrapperState(getWrapperFromInput(target));
    });

    document.addEventListener('change', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (!target.classList.contains('password-input')) return;
        setWrapperState(getWrapperFromInput(target));
    });

    document.addEventListener('focusin', function (e) {
        const target = e.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (!target.classList.contains('password-input')) return;
        setWrapperState(getWrapperFromInput(target));
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest ? e.target.closest('.toggle-password') : null;
        if (!btn) return;

        const wrapper = btn.closest('.password-wrapper');
        if (!wrapper) return;

        const input = wrapper.querySelector('.password-input');
        if (!input) return;

        if ((input.value || '').length === 0) {
            setWrapperState(wrapper);
            return;
        }

        const nextType = input.type === 'password' ? 'text' : 'password';
        input.type = nextType;
        wrapper.classList.toggle('is-visible', nextType === 'text');
        input.focus({ preventScroll: true });
    });
});
