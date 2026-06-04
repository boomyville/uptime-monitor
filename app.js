// Provide a safe global function for inline onclick handlers.
// It will dispatch an event that initDashboard listens for, and will be
// overridden by a richer implementation once the dashboard initializes.
console.log('[app.js] Script loaded');

function setChartRange(days) {
    console.log('[setChartRange] Called with days:', days);
    try { document.dispatchEvent(new CustomEvent('uptime-setChartRange', { detail: days })); } catch (e) { console.error('[setChartRange] Error:', e); }
}
window.setChartRange = setChartRange;

document.addEventListener('DOMContentLoaded', () => {
    console.log('[DOMContentLoaded] Event fired');
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
    let currentChartSiteId = null; // id of the site currently shown in the chart modal
    let cachedAggregates = {}; // cache aggregated datasets by days (e.g., 7,30)
    let allChartIsAggregated = false; // true when allChartData contains server-side aggregated buckets
    let activeChartRequestToken = 0; // monotonic token to ignore older in-flight responses
    let suppressZoomEvent = false; // guard to prevent zoom events from overriding programmatic range changes

    function showLoadingOverlay() {
        const chartContainer = document.getElementById('chartContainer');
        if (!chartContainer) return;
        let overlay = chartContainer.querySelector('.chart-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'chart-loading-overlay';
            // Inline styles so no CSS edits are required
            overlay.style.position = 'absolute';
            overlay.style.left = '0';
            overlay.style.top = '0';
            overlay.style.right = '0';
            overlay.style.bottom = '0';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.flexDirection = 'column';
            overlay.style.background = 'rgba(0,0,0,0.48)';
            overlay.style.color = '#fff';
            overlay.style.zIndex = '9999';
            overlay.innerHTML = '<div style="width:40px;height:40px;border:4px solid rgba(255,255,255,0.2);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:8px"></div><div>Loading…</div>';
            chartContainer.style.position = chartContainer.style.position || 'relative';
            chartContainer.appendChild(overlay);

            // Add simple spin keyframes if not present
            if (!document.getElementById('chart-loading-spin')) {
                const style = document.createElement('style');
                style.id = 'chart-loading-spin';
                style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
                document.head.appendChild(style);
            }
        }
        overlay.style.display = 'flex';
    }

    function hideLoadingOverlay() {
        const chartContainer = document.getElementById('chartContainer');
        if (!chartContainer) return;
        const overlay = chartContainer.querySelector('.chart-loading-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    function filterDataByDays(data, days) {
        const now = new Date();
        const cutoffDate = new Date(now.getTime() - days * 24 * 60 * 60 * 1000);
        console.log('[filterDataByDays] Now:', now.toISOString(), 'Cutoff for', days, 'days:', cutoffDate.toISOString());
        
        if (data.length > 0) {
            const firstTime = getCheckedAtMs(data[0]);
            const lastTime = getCheckedAtMs(data[data.length - 1]);
            console.log('[filterDataByDays] First item time:', new Date(firstTime).toISOString(), 'Last item time:', new Date(lastTime).toISOString());
        }
        
        const filtered = data.filter((item) => {
            const itemTime = getCheckedAtMs(item);
            const passes = itemTime >= cutoffDate.getTime();
            return passes;
        });
        
        console.log('[filterDataByDays] Input:', data.length, 'Output:', filtered.length);
        return filtered;
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
        console.log('[updateChartRangeButtons] Setting active days to', days);
        document.querySelectorAll('#chartTimeRangeButtons .chart-range-button').forEach((button) => {
            button.classList.toggle('is-active', Number(button.dataset.days) === days);
        });
    }

    function getChartBounds(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return null;
        }

        const timestamps = data
            .map((item) => getCheckedAtMs(item))
            .filter((timestamp) => Number.isFinite(timestamp))
            .sort((left, right) => left - right);

        if (timestamps.length === 0) {
            return null;
        }

        const min = timestamps[0];
        const max = timestamps[timestamps.length - 1];

        if (min === max) {
            return {
                min: min - 60 * 60 * 1000,
                max: max + 60 * 60 * 1000,
            };
        }

        return {
            min,
            max,
        };
    }

    function normalizeChartRows(data) {
        if (!Array.isArray(data)) {
            return [];
        }

        return data
            .filter((item) => {
                const timestamp = getCheckedAtMs(item);
                return Number.isFinite(timestamp) && timestamp > 0;
            })
            .sort((left, right) => getCheckedAtMs(left) - getCheckedAtMs(right));
    }

    function renderChartForDays(days) {
        console.log('[renderChartForDays] Called with days:', days, 'allChartData length:', allChartData.length);
        activeChartDays = days;
        const requestToken = ++activeChartRequestToken;
        updateChartRangeButtons(days);
 
        // For larger ranges (7d+), request server-side aggregated data.
        if (Number.isFinite(days) && days >= 7 && currentChartSiteId) {
            // Force a fresh fetch by adding a cache-busting param.
            showLoadingOverlay();
            const reqDays = days;
            const dayMs = 24 * 60 * 60 * 1000;
            const urlBase = 'index.php?get_stats=' + encodeURIComponent(currentChartSiteId) + '&days=' + encodeURIComponent(days);
            function fetchAggregated() {
                const url = urlBase + '&_=' + Date.now();
                console.log('[renderChartForDays] Fetching aggregated data url:', url);
                fetch(url)
                    .then((resp) => {
                        const headerDays = resp.headers.get('X-Requested-Days');
                        if (headerDays && Number(headerDays) !== Number(reqDays)) {
                            throw new Error('Mismatched days in response header: ' + headerDays);
                        }
                        return resp.json();
                    })
                    .then((data) => {
                        if (requestToken !== activeChartRequestToken) {
                            console.warn('[renderChartForDays] Ignoring outdated aggregated response token:', requestToken, 'active token:', activeChartRequestToken);
                            hideLoadingOverlay();
                            return;
                        }

                        // Ignore stale responses if the user has switched ranges
                        if (activeChartDays !== reqDays) {
                            console.warn('[renderChartForDays] Ignoring stale aggregated response for days:', reqDays, 'active:', activeChartDays);
                            hideLoadingOverlay();
                            return;
                        }

                        const normalized = normalizeChartRows(data);
                        if (normalized.length === 0) {
                            throw new Error('Aggregated response is empty or invalid');
                        }

                        const firstTime = getCheckedAtMs(normalized[0]);
                        const expectedMin = Date.now() - reqDays * dayMs;
                        const tolerance = 24 * 60 * 60 * 1000;
                        if (Number.isFinite(firstTime) && firstTime > (expectedMin + tolerance)) {
                            console.warn('[renderChartForDays] Aggregated response is newer than requested window start; rendering available history only.');
                        }

                        cachedAggregates[reqDays] = normalized;
                        allChartData = normalized;
                        allChartIsAggregated = true;
                        const filtered = filterDataByDays(allChartData, reqDays);
                        console.log('[renderChartForDays] Aggregated result length:', filtered.length);
                        renderChart(filtered, reqDays);
                        hideLoadingOverlay();
                    })
                    .catch((err) => {
                        if (requestToken !== activeChartRequestToken) {
                            console.warn('[renderChartForDays] Ignoring outdated aggregated error token:', requestToken, 'active token:', activeChartRequestToken);
                            hideLoadingOverlay();
                            return;
                        }

                        console.error('[renderChartForDays] Failed to fetch validated aggregated data:', err);
                        hideLoadingOverlay();
                        // Do not fall back to previously aggregated data with different bucketing.
                        // Instead, try to render whatever raw data we have available for the range.
                        const rawFallback = filterDataByDays(allChartData, days);
                        renderChart(rawFallback, days);
                    });
            }

            // Clear any cached smaller-range aggregates to avoid accidental reuse (e.g., 7d cached used for 30d)
            Object.keys(cachedAggregates).forEach((k) => {
                if (Number(k) !== Number(reqDays)) delete cachedAggregates[k];
            });

            fetchAggregated();
            return;
        }

        // Small ranges: ensure we have raw (non-aggregated) data for accurate rendering.
        if (Number.isFinite(days) && days < 7 && currentChartSiteId) {
            if (allChartIsAggregated || allChartData.length === 0) {
                // Fetch raw rows for the requested small window
                showLoadingOverlay();
                const reqDaysSmall = days;
                fetch('index.php?get_stats=' + encodeURIComponent(currentChartSiteId) + '&days=' + encodeURIComponent(days))
                    .then((resp) => resp.json())
                    .then((data) => {
                        if (requestToken !== activeChartRequestToken) {
                            console.warn('[renderChartForDays] Ignoring outdated raw response token:', requestToken, 'active token:', activeChartRequestToken);
                            hideLoadingOverlay();
                            return;
                        }

                        // Ignore if user already switched ranges
                        if (activeChartDays !== reqDaysSmall) {
                            console.warn('[renderChartForDays] Ignoring stale raw response for days:', reqDaysSmall, 'active:', activeChartDays);
                            hideLoadingOverlay();
                            return;
                        }

                        allChartData = data;
                        allChartIsAggregated = false;
                        const filtered = filterDataByDays(allChartData, reqDaysSmall);
                        console.log('[renderChartForDays] Fetched raw data for small window, length:', filtered.length);
                        renderChart(filtered, reqDaysSmall);
                        hideLoadingOverlay();
                    })
                    .catch((err) => {
                        if (requestToken !== activeChartRequestToken) {
                            console.warn('[renderChartForDays] Ignoring outdated raw error token:', requestToken, 'active token:', activeChartRequestToken);
                            hideLoadingOverlay();
                            return;
                        }

                        console.error('[renderChartForDays] Failed to fetch raw data for small window:', err);
                        hideLoadingOverlay();
                        const fallback = filterDataByDays(allChartData, days);
                        renderChart(fallback, days);
                    });

                return;
            }
        }

        // Default: use client-side filtered data (suitable when we already have raw data)
        const filtered = filterDataByDays(allChartData, days);
        console.log('[renderChartForDays] Filtered to:', filtered.length, 'items');
        renderChart(filtered, days);
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

    // Override the safe global setChartRange so inline handlers delegate
    // to the central renderer (which will fetch aggregated data for 7d/30d).
    window.setChartRange = function setChartRange(days) {
        if (!Number.isFinite(days)) {
            return;
        }
        renderChartForDays(Number(days));
    };

    function renderChart(data, requestedDays) {
        console.log('[renderChart] Called with', data.length, 'data points');
        const chartContainer = document.getElementById('chartContainer');
        if (!chartContainer) {
            console.log('[renderChart] chartContainer NOT FOUND');
            return;
        }

        chartContainer.innerHTML = '';

        // Prepare series for ApexCharts
        const seriesData = data.map((item) => {
            const y = Number(item.cumulative_time || item.response_time || 0);
            return {
                x: getCheckedAtMs(item),
                y: y > 0 ? y : null   // null renders as a gap; 0 would break log scale
            };
        });
        // Always use the requested time range for the x-axis, not the data bounds.
        // This prevents the chart from appearing to show a shorter window than selected.
        const dayMs = 24 * 60 * 60 * 1000;
        const now = Date.now();
        const effectiveDays = Number.isFinite(requestedDays) ? requestedDays : activeChartDays;
        const xAxisRange = {
            min: now - effectiveDays * dayMs,
            max: now,
        };

        console.log('[renderChart] x-axis range for', effectiveDays, 'days:', new Date(xAxisRange.min).toISOString(), '->', new Date(xAxisRange.max).toISOString());

        currentStatuses = data.map((item) => item.health_status || 'unknown');

        const options = {
            chart: {
                type: 'bar',
                height: 360,
                animations: { enabled: true },
                zoom: { enabled: true, type: 'x' },
                toolbar: { show: true },
                events: {
                    zoomed: (chartContext, { xaxis }) => {
                        // Ignore zoom events fired programmatically during range changes
                        if (suppressZoomEvent) {
                            console.log('[zoomed] Suppressed (programmatic)');
                            return;
                        }
                        // After a user-initiated zoom, optionally switch time periods
                        if (xaxis && xaxis.min && xaxis.max) {
                            const rangeMs = xaxis.max - xaxis.min;
                            const dayMs = 24 * 60 * 60 * 1000;
                            if (activeChartDays === 1 && rangeMs > dayMs * 1.5) {
                                renderChartForDays(7);
                            } else if (activeChartDays === 7 && rangeMs > dayMs * 8) {
                                renderChartForDays(30);
                            }
                        }
                    }
                }
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
                min: xAxisRange.min,
                max: xAxisRange.max,
                labels: {
                    formatter: (_value, timestamp) => formatChartTime(timestamp)
                }
            },
            yaxis: {
                title: { text: 'ms' },
                logarithmic: true,
                logBase: 10,
                labels: {
                    formatter: (val) => val == null ? '' : Math.round(val) + 'ms'
                }
            },
            tooltip: {
                theme: 'dark',
                style: {
                    fontSize: '12px'
                },
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

        // Suppress zoom events while the chart initialises to prevent the
        // 'zoomed' callback from firing with the initial viewport and
        // incorrectly switching to a different time range.
        suppressZoomEvent = true;
        myChart.render().then(() => {
            // Keep the x-axis pinned to the requested range after render.
            // Do NOT update to chartBounds here — that would shrink the window
            // back to just the data extent (e.g. 7 days when 30 was requested).
            suppressZoomEvent = false;
        });

        if (data.length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'empty-state';
            emptyState.textContent = 'No checks in this time range.';
            chartContainer.appendChild(emptyState);
        }
    }

    window.showChart = function showChart(id, name) {
        console.log('[showChart] Opening chart for id:', id, 'name:', name);
        const modal = document.getElementById('chartModal');
        const modalTitle = document.getElementById('modalTitle');
        const chartContainer = document.getElementById('chartContainer');
        if (!modal || !modalTitle || !chartContainer) {
            console.log('[showChart] Missing elements - modal:', !!modal, 'title:', !!modalTitle, 'container:', !!chartContainer);
            return;
        }

        currentChartSiteId = id;
        activeChartDays = 1;
        modalTitle.innerText = 'Latency & Health - ' + name;
        // Open modal immediately to improve perceived responsiveness
        modal.classList.add('is-open');

        // Show loading overlay while we fetch the initial dataset
        showLoadingOverlay();

        // Fetch a small dataset (days=1) for immediate rendering of 1D view
        fetch('index.php?get_stats=' + encodeURIComponent(id) + '&days=1')
            .then((response) => response.json())
            .then((data) => {
                console.log('[showChart] Initial data received, length:', data.length);
                allChartData = data;
                allChartIsAggregated = false;
                renderChart(filterDataByDays(allChartData, activeChartDays), activeChartDays);
                updateChartRangeButtons(activeChartDays);
                hideLoadingOverlay();
            })
            .catch((err) => {
                console.error('[showChart] Fetch error (initial):', err);
                hideLoadingOverlay();
            });

        // Prefetch only the 7-day aggregated dataset in the background (avoid prefetching 30d which may be stale).
        [7].forEach((d) => {
            fetch('index.php?get_stats=' + encodeURIComponent(id) + '&days=' + encodeURIComponent(d))
                .then((r) => r.json())
                .then((dataset) => {
                    console.log('[showChart] Prefetched aggregated', d, 'day dataset, length:', dataset.length);
                    cachedAggregates[d] = dataset;
                })
                .catch((e) => {
                    console.warn('[showChart] Failed to prefetch aggregated', d, 'day dataset:', e);
                });
        });
    };

    // Chart range buttons are wired via inline onclick="setChartRange(...)" in HTML.
    // Keep a single execution path to prevent duplicate requests/race conditions.

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