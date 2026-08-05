document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.querySelector('.un-sidebar');
    const overlay = document.querySelector('.un-overlay');
    const toggle = document.querySelector('.nav-toggle');

    function openSidebar() {
        body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        body.classList.remove('sidebar-open');
    }

    // --- 點漢堡：切換 sidebar ---
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (body.classList.contains('sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    // --- 點遮罩關閉 ---
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // --- 點 sidebar 選單自動收回 ---
    const links = document.querySelectorAll('.un-sidebar .sidebar-link');
    links.forEach((link) => {
        link.addEventListener('click', function () {
            if (window.innerWidth < 992) closeSidebar();
        });
    });

    // =====================
    // ⭐ 手勢控制（右滑開啟 / 左滑關閉）
    // =====================
    let touchStartX = 0;
    let touchEndX = 0;

    document.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].screenX;
    });

    document.addEventListener('touchend', function (e) {
        touchEndX = e.changedTouches[0].screenX;
        handleGesture();
    });

    function handleGesture() {
        const diff = touchEndX - touchStartX;

        // ➤ 右滑（從左邊緣開始）
        if (touchStartX < 40 && diff > 50) {
            openSidebar();
        }

        // ➤ 左滑（任何地方）
        if (diff < -50 && body.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    }
});
