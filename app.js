// Provide a safe global function for inline onclick handlers.
// It will dispatch an event that initDashboard listens for, and will be
// overridden by a richer implementation once the dashboard initializes.
function setChartRange(days) {
    try { document.dispatchEvent(new CustomEvent('uptime-setChartRange', { detail: days })); } catch (e) { /* ignore */ }
}
window.setChartRange = setChartRange;

document.addEventListener('DOMContentLoaded', () => {
    initStarfield();
    initDashboard();
});

function initStarfield() {
    const canvas = document.createElement('canvas');
    canvas.className = 'starfield-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.prepend(canvas);

    const context = canvas.getContext('2d');
    const starCount = Math.min(170, Math.max(110, Math.floor(window.innerWidth / 10)));
    const stars = [];
    const mouse = { x: window.innerWidth / 2, y: window.innerHeight / 3, active: false };
    let width = 0;
    let height = 0;

    function resizeCanvas() {
        const ratio = window.devicePixelRatio || 1;
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = Math.floor(width * ratio);
        canvas.height = Math.floor(height * ratio);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function createStar() {
        return {
            x: Math.random() * width,
            y: Math.random() * height,
            radius: Math.random() * 1.8 + 0.35,
            speedX: (Math.random() - 0.5) * 0.12,
            speedY: (Math.random() - 0.5) * 0.08,
            twinkle: Math.random() * Math.PI * 2,
            alpha: Math.random() * 0.55 + 0.2,
        };
    }

    function resetStars() {
        stars.length = 0;
        for (let index = 0; index < starCount; index += 1) {
            stars.push(createStar());
        }
    }

    function draw() {
        context.clearRect(0, 0, width, height);
        context.fillStyle = 'rgba(255, 255, 255, 0.92)';
        const driftX = mouse.active ? (mouse.x - width / 2) * 0.0003 : 0;
        const driftY = mouse.active ? (mouse.y - height / 2) * 0.0003 : 0;

        stars.forEach((star, index) => {
            const parallaxX = mouse.active ? (mouse.x - width / 2) * (0.0008 + star.radius * 0.0002) : 0;
            const parallaxY = mouse.active ? (mouse.y - height / 2) * (0.0008 + star.radius * 0.0002) : 0;
            star.x += star.speedX + driftX;
            star.y += star.speedY + driftY;
            star.twinkle += 0.003 + star.radius * 0.0008;

            if (star.x > width + 12) star.x = -12;
            if (star.x < -12) star.x = width + 12;
            if (star.y > height + 12) star.y = -12;
            if (star.y < -12) star.y = height + 12;

            const cycle = (star.twinkle % 1);
            const fade = cycle < 0.5 ? cycle * 2 : (1 - cycle) * 2;
            const glow = 0.12 + fade * 0.68;
            const size = star.radius * (0.9 + fade * 0.22);
            const x = star.x + parallaxX;
            const y = star.y + parallaxY;

            context.beginPath();
            context.globalAlpha = Math.min(1, star.alpha + glow);
            context.shadowColor = 'rgba(34, 211, 238, 0.55)';
            context.shadowBlur = 6;
            context.arc(x, y, size, 0, Math.PI * 2);
            context.fill();
            context.shadowBlur = 0;

            for (let otherIndex = index + 1; otherIndex < stars.length; otherIndex += 1) {
                const other = stars[otherIndex];
                const otherX = other.x + (mouse.active ? (mouse.x - width / 2) * (0.0008 + other.radius * 0.0002) : 0);
                const otherY = other.y + (mouse.active ? (mouse.y - height / 2) * (0.0008 + other.radius * 0.0002) : 0);
                const distance = Math.hypot(otherX - x, otherY - y);
                if (distance < 120) {
                    context.beginPath();
                    context.globalAlpha = (1 - distance / 120) * 0.08;
                    context.strokeStyle = 'rgba(34, 211, 238, 1)';
                    context.lineWidth = 1;
                    context.moveTo(x, y);
                    context.lineTo(otherX, otherY);
                    context.stroke();
                }
            }
        });

        context.globalAlpha = 1;
        requestAnimationFrame(draw);
    }

    resizeCanvas();
    resetStars();
    draw();

    window.addEventListener('resize', () => {
        resizeCanvas();
        resetStars();
    });

    window.addEventListener('mousemove', (event) => {
        mouse.x = event.clientX;
        mouse.y = event.clientY;
        mouse.active = true;
    });

    window.addEventListener('mouseleave', () => {
        mouse.active = false;
    });
}

function initDashboard() {
    const chartModal = document.getElementById('chartModal');
    if (!chartModal) {
        return;
    }

    let myChart;
    let allChartData = [];
    let activeChartDays = 1;

    function filterDataByDays(data, days) {
        const now = new Date();
        const cutoffDate = new Date(now.getTime() - days * 24 * 60 * 60 * 1000);
        return data.filter((item) => getCheckedAtMs(item) >= cutoffDate.getTime());
    }

    function getServerTimezone() {
        return window.UPTIME_TIMEZONE || 'UTC';
    }

    function getCheckedAtMs(item) {
        if (Number.isFinite(Number(item.checked_at_ms))) {
            return Number(item.checked_at_ms);
        }

        const parsed = Date.parse(item.checked_at);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatChartTime(timestamp) {
        if (!Number.isFinite(Number(timestamp))) {
            return '';
        }

        try {
            return new Intl.DateTimeFormat('en-US', {
                timeZone: getServerTimezone(),
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            }).format(new Date(Number(timestamp)));
        } catch (e) {
            return new Date(Number(timestamp)).toLocaleString();
        }
    }

    function updateChartRangeButtons(days) {
        document.querySelectorAll('#chartTimeRangeButtons .chart-range-button').forEach((button) => {
            button.classList.toggle('is-active', Number(button.dataset.days) === days);
        });
    }

    function renderChartForDays(days) {
        activeChartDays = days;
        updateChartRangeButtons(days);
        renderChart(filterDataByDays(allChartData, days));
    }

    // Support external callers (inline onclick or other scripts) by listening
    // for the dispatched custom event from the global setChartRange helper.
    document.addEventListener('uptime-setChartRange', (ev) => {
        const days = Number(ev && ev.detail);
        if (Number.isFinite(days)) {
            renderChartForDays(days);
        }
    });

    // Use ApexCharts for a proper bar chart with datetime x-axis and zoom support.
    let currentStatuses = [];

    // Override the safe global setChartRange so inline handlers call this implementation.
    window.setChartRange = function setChartRange(days) {
        if (!Number.isFinite(days)) {
            return;
        }
        activeChartDays = days;
        updateChartRangeButtons(days);

        if (!myChart) {
            // If chart isn't rendered yet, just re-render with filtered data when available
            renderChart(filterDataByDays(allChartData, days));
            return;
        }

        const max = Date.now();
        const min = max - days * 24 * 60 * 60 * 1000;
        try {
            // Prefer zoomX if available
            if (typeof myChart.zoomX === 'function') {
                myChart.zoomX(min, max);
            } else {
                myChart.updateOptions({ xaxis: { min, max } }, false, false);
            }
        } catch (e) {
            // Fallback: re-render filtered data
            renderChart(filterDataByDays(allChartData, days));
        }
    };

    function renderChart(data) {
        const chartContainer = document.getElementById('chartContainer');
        if (!chartContainer) {
            return;
        }

        chartContainer.innerHTML = '';

        // Prepare series for ApexCharts
        const seriesData = data.map((item) => ({
            x: getCheckedAtMs(item),
            y: Number(item.cumulative_time || item.response_time || 0)
        }));

        currentStatuses = data.map((item) => item.health_status || 'unknown');

        const options = {
            chart: {
                type: 'bar',
                height: 360,
                animations: { enabled: true },
                zoom: { enabled: true, type: 'x' },
                toolbar: { show: true }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '60%',
                    distributed: false
                }
            },
            dataLabels: { enabled: false },
            series: [
                {
                    name: 'Latency (ms)',
                    data: seriesData
                }
            ],
            xaxis: {
                type: 'datetime',
                labels: {
                    formatter: (_value, timestamp) => formatChartTime(timestamp)
                }
            },
            yaxis: {
                title: { text: 'ms' }
            },
            tooltip: {
                x: {
                    formatter: (timestamp) => formatChartTime(timestamp)
                },
                y: { formatter: (val) => `${Number(val).toFixed(2)} ms` }
            },
            colors: [function ({ dataPointIndex }) {
                const status = currentStatuses[dataPointIndex] || 'unknown';
                return status === 'green' ? '#34d399' : status === 'yellow' ? '#fbbf24' : '#fb7185';
            }]
        };

        const chartEl = document.createElement('div');
        chartContainer.appendChild(chartEl);

        if (myChart) {
            try { myChart.destroy(); } catch (e) {}
            myChart = null;
        }

        myChart = new ApexCharts(chartEl, options);
        myChart.render().then(() => {
            // Apply the current active range if set
            if (Number.isFinite(activeChartDays) && activeChartDays > 0) {
                const max = Date.now();
                const min = max - activeChartDays * 24 * 60 * 60 * 1000;
                try { myChart.updateOptions({ xaxis: { min, max } }, false, false); } catch (e) {}
            }
        });

        if (data.length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'empty-state';
            emptyState.textContent = 'No checks in this time range.';
            chartContainer.appendChild(emptyState);
        }
    }

    window.showChart = function showChart(id, name) {
        const modal = document.getElementById('chartModal');
        const modalTitle = document.getElementById('modalTitle');
        const chartContainer = document.getElementById('chartContainer');
        if (!modal || !modalTitle || !chartContainer) {
            return;
        }

        modalTitle.innerText = 'Latency & Health - ' + name;
        fetch('index.php?get_stats=' + id)
            .then((response) => response.json())
            .then((data) => {
                allChartData = data;
                renderChart(filterDataByDays(allChartData, activeChartDays));
                modal.classList.add('is-open');
            });
    };

    document.querySelectorAll('#chartTimeRangeButtons .chart-range-button').forEach((button) => {
        button.addEventListener('click', () => {
            renderChartForDays(Number(button.dataset.days || '1'));
        });
    });

    window.closeChart = function closeChart() {
        const modal = document.getElementById('chartModal');
        if (modal) {
            modal.classList.remove('is-open');
        }
        allChartData = [];
        activeChartDays = 1;
        updateChartRangeButtons(1);
    };

    window.openDeleteModal = function openDeleteModal(id, name) {
        const deleteSiteId = document.getElementById('deleteSiteId');
        const deleteMessage = document.getElementById('deleteMessage');
        const deleteModal = document.getElementById('deleteModal');
        if (!deleteSiteId || !deleteMessage || !deleteModal) {
            return;
        }
        deleteSiteId.value = id;
        deleteMessage.innerText = `This will permanently delete ${name} and all of its logs.`;
        deleteModal.classList.add('is-open');
    };

    window.closeDeleteModal = function closeDeleteModal() {
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.classList.remove('is-open');
        }
    };

    window.openSettingsModal = function openSettingsModal(id, name, timeout, retries) {
        const settingsSiteId = document.getElementById('settingsSiteId');
        const settingsTitle = document.getElementById('settingsTitle');
        const settingsTimeout = document.getElementById('settingsTimeout');
        const settingsRetries = document.getElementById('settingsRetries');
        const settingsModal = document.getElementById('settingsModal');
        if (!settingsSiteId || !settingsTitle || !settingsTimeout || !settingsRetries || !settingsModal) {
            return;
        }
        settingsSiteId.value = id;
        settingsTitle.innerText = `Settings for ${name}`;
        settingsTimeout.value = timeout;
        settingsRetries.value = retries;
        settingsModal.classList.add('is-open');
    };

    window.closeSettingsModal = function closeSettingsModal() {
        const settingsModal = document.getElementById('settingsModal');
        if (settingsModal) {
            settingsModal.classList.remove('is-open');
        }
    };

    chartModal.addEventListener('click', (event) => {
        if (event.target.id === 'chartModal') {
            closeChart();
        }
    });

    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', (event) => {
            if (event.target.id === 'deleteModal') {
                closeDeleteModal();
            }
        });
    }

    const settingsModal = document.getElementById('settingsModal');
    if (settingsModal) {
        settingsModal.addEventListener('click', (event) => {
            if (event.target.id === 'settingsModal') {
                closeSettingsModal();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeChart();
            closeDeleteModal();
            closeSettingsModal();
        }
    });
}
