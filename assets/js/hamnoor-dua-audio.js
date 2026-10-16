(function () {
    'use strict';

    var activeAudio = null;

    document.querySelectorAll('[data-dua-audio-player]').forEach(function (root) {
        var audio = root.querySelector('[data-dua-audio-element]');
        var select = root.querySelector('[data-dua-audio-select]');
        var reciterName = root.querySelector('[data-dua-audio-reciter-name]');
        var message = root.querySelector('[data-dua-audio-message]');
        var placeholder = null;
        var data = [];

        if (!audio) { return; }

        try {
            var json = root.querySelector('[data-dua-audio-data]');
            data = json ? JSON.parse(json.textContent || '[]') : [];
        } catch (e) {
            data = [];
        }

        function choose(index) {
            var item = data[index];
            if (!item || !item.url) { return; }
            var wasPlaying = !audio.paused;
            audio.pause();
            audio.src = item.url;
            audio.load();
            if (reciterName) { reciterName.textContent = item.reciter || ''; }
            if (wasPlaying) {
                audio.play().catch(function () {
                    if (message) { message.textContent = 'پخش صوت ممکن نشد؛ متن دعا همچنان در دسترس است.'; }
                });
            }
        }

        if (select) {
            select.addEventListener('change', function () {
                choose(parseInt(select.value, 10) || 0);
            });
        }

        function clearSticky() {
            root.classList.remove('is-sticky');
            if (placeholder) {
                placeholder.remove();
                placeholder = null;
            }
        }

        function updateSticky() {
            if (audio.paused || audio.ended) {
                clearSticky();
                return;
            }
            var reference = placeholder || root;
            var rect = reference.getBoundingClientRect();
            if (rect.top <= 8 && rect.bottom < window.innerHeight) {
                if (!placeholder) {
                    placeholder = document.createElement('div');
                    placeholder.className = 'hamnoor-dua-audio-placeholder';
                    placeholder.style.height = root.offsetHeight + 'px';
                    root.before(placeholder);
                }
                root.classList.add('is-sticky');
            } else {
                clearSticky();
            }
        }

        audio.addEventListener('play', function () {
            if (activeAudio && activeAudio !== audio) { activeAudio.pause(); }
            activeAudio = audio;
            if (message) { message.textContent = ''; }
            updateSticky();
        });
        audio.addEventListener('pause', clearSticky);
        audio.addEventListener('ended', clearSticky);
        audio.addEventListener('error', function () {
            clearSticky();
            if (message) { message.textContent = 'این صوت اکنون در دسترس نیست؛ متن دعا بدون اختلال قابل مطالعه است.'; }
        });

        window.addEventListener('scroll', updateSticky, { passive: true });
        window.addEventListener('resize', clearSticky, { passive: true });
    });
}());
