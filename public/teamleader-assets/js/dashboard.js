document.addEventListener("DOMContentLoaded", function () {
    var dashboard = document.getElementById("teamLeaderDashboard");

    if (!dashboard) {
        return;
    }

    var refreshUrl = dashboard.getAttribute("data-refresh-url");

    if (!refreshUrl) {
        return;
    }

    function refreshStats() {
        fetch(refreshUrl, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.stats) {
                    return;
                }

                Object.keys(payload.stats).forEach(function (key) {
                    var node = dashboard.querySelector(
                        '[data-stat="' + key + '"]',
                    );
                    if (node) {
                        node.textContent = String(payload.stats[key] || 0);
                    }
                });
            })
            .catch(function () {
                return null;
            });
    }

    window.setInterval(refreshStats, 30000);
});
