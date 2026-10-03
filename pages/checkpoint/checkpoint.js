console.log('Checkpoint JS loaded');

document.addEventListener('DOMContentLoaded', function () {

    const track = document.querySelector('.checkpoint-track');
    const pages = document.querySelectorAll('.checkpoint-page');
    const dotsWrap = document.getElementById('cpDots');

    if (!track || pages.length === 0) {
        console.error('Checkpoint DOM not found');
        return;
    }

    let current = 0;
    const total = pages.length;
    let locked = false;

    /* =========================
       Core GoTo
    ========================= */
    function goTo(index) {
        if (index < 0 || index >= total) return;
        current = index;

        const card = pages[index];
        const trackRect = track.getBoundingClientRect();
        const cardRect = card.getBoundingClientRect();

        const cardCenter =
            cardRect.left - trackRect.left + cardRect.width / 2;

        const viewportCenter = window.innerWidth / 2;

        const offset = cardCenter - viewportCenter;

        track.style.transform = `translateX(-${offset}px)`;

        pages.forEach((p, i) => {
            p.classList.toggle('active', i === index);
        });

        updateDots();
    }





    /* =========================
       Dots
    ========================= */
    function createDots() {
        pages.forEach((_, i) => {
            const dot = document.createElement('div');
            dot.className = 'cp-dot';
            dot.addEventListener('click', () => goTo(i));
            dotsWrap.appendChild(dot);
        });
        updateDots();
    }

    function updateDots() {
        const dots = dotsWrap.querySelectorAll('.cp-dot');
        dots.forEach((d, i) => {
            d.classList.toggle('active', i === current);
        });
    }

    createDots();

    /* =========================
       Wheel Scroll (Desktop)
    ========================= */
    window.addEventListener('wheel', function (e) {
        if (locked) return;
        locked = true;

        if (e.deltaY > 0) {
            goTo(current + 1);
        } else {
            goTo(current - 1);
        }

        setTimeout(() => locked = false, 700);
    }, { passive: true });

    /* =========================
       Touch Swipe (Mobile)
    ========================= */
    let touchStartX = 0;

    window.addEventListener('touchstart', e => {
        touchStartX = e.touches[0].clientX;
    });

    window.addEventListener('touchend', e => {
        const dx = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(dx) < 50) return;

        if (dx < 0) {
            goTo(current + 1);
        } else {
            goTo(current - 1);
        }
    });

});
