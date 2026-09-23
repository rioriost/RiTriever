(function () {
  "use strict";

  var config = window.ritrieverBackfill || null;
  var root = document.getElementById("ritriever-backfill-progress");
  if (!config || !root) {
    return;
  }

  var statusText = root.querySelector("[data-ritriever-status-text]");
  var detailText = root.querySelector("[data-ritriever-detail-text]");
  var bar = root.querySelector("[data-ritriever-progress-bar]");
  var percentText = root.querySelector("[data-ritriever-percent]");
  var controls = root.querySelectorAll("[data-ritriever-control]");
  var currentStatus = "idle";
  var runAllowed = !!config.autoStart;
  var workerActive = false;
  var controlActive = false;
  var timer = null;
  var epoch = 0;
  var requestId = 0;
  var appliedRequestId = 0;
  var consecutiveErrors = 0;

  function setMessage(message) {
    if (detailText && message) {
      detailText.textContent = message;
    }
  }

  function runnable(status) {
    return status === "queued" || status === "running";
  }

  function setControlStates() {
    Array.prototype.forEach.call(controls, function (button) {
      var action = button.getAttribute("data-ritriever-control");
      button.disabled = controlActive || (
        action === "pause" ? !runnable(currentStatus) :
        action === "resume" ? currentStatus !== "paused" :
        !(runnable(currentStatus) || currentStatus === "paused")
      );
    });
  }

  function updateProgress(state) {
    var total = Math.max(0, parseInt(state.total, 10) || 0);
    var processed = Math.max(0, parseInt(state.processed, 10) || 0);
    var errors = Math.max(0, parseInt(state.errors, 10) || 0);
    var percent = total > 0 ? Math.floor(Math.min(processed, total) / total * 100) : 0;
    currentStatus = state.status || "idle";
    if (bar) {
      bar.style.width = percent + "%";
    }
    if (percentText) {
      percentText.textContent = percent + "%";
    }
    if (statusText) {
      statusText.textContent = config.i18n.progress
        .replace("%1$d", processed).replace("%2$d", total).replace("%3$d", errors);
      if (config.i18n.remaining && state.remaining !== undefined) {
        statusText.textContent += " " + config.i18n.remaining
          .replace("%1$d", state.remaining)
          .replace("%2$d", state.remaining_failures === undefined ? errors : state.remaining_failures);
      }
    }
    setMessage(state.message || (
      currentStatus === "complete" ? config.i18n.complete :
      currentStatus === "failed" || currentStatus === "completed_with_errors" ? config.i18n.failed :
      runnable(currentStatus) ? config.i18n.running : config.i18n.idle
    ));
    setControlStates();
  }

  function clearTimer() {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  }

  function schedule(callback, delay) {
    clearTimer();
    timer = window.setTimeout(function () {
      timer = null;
      callback();
    }, delay);
  }

  function request(action, generation) {
    var id = ++requestId;
    var formData = new window.FormData();
    formData.append("action", action);
    formData.append("nonce", config.nonce);
    return window.fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: formData,
    }).then(function (response) {
      if (!response.ok) {
        throw new Error("HTTP " + response.status);
      }
      return response.json();
    }).then(function (payload) {
      if (generation !== epoch || id < appliedRequestId) {
        return null;
      }
      if (!payload || !payload.success || !payload.data) {
        throw new Error(payload && payload.data && payload.data.message || config.i18n.failed);
      }
      appliedRequestId = id;
      consecutiveErrors = 0;
      updateProgress(payload.data);
      return payload.data;
    });
  }

  function onError(error, generation, retry) {
    if (generation !== epoch) {
      return;
    }
    consecutiveErrors += 1;
    if (consecutiveErrors >= (config.maxConsecutiveErrors || 5)) {
      runAllowed = false;
      setMessage(config.i18n.failed + " " + error.message);
      setControlStates();
      return;
    }
    setMessage(config.i18n.retrying.replace("%s", error.message));
    schedule(retry, config.errorDelayMs || 5000);
  }

  function startWorkers() {
    if (!runAllowed || controlActive || workerActive || timer !== null || !runnable(currentStatus)) {
      return;
    }
    workerActive = true;
    var generation = epoch;
    request("ritriever_backfill_run", generation)
      .catch(function (error) {
        onError(error, generation, startWorkers);
      })
      .then(function () {
        workerActive = false;
        if (runAllowed && !controlActive && runnable(currentStatus) && timer === null) {
          schedule(startWorkers, config.delayMs || 500);
        }
      });
  }

  function readStatus() {
    var generation = epoch;
    request("ritriever_backfill_status", generation)
      .then(function (state) {
        if (state) {
          startWorkers();
        }
      })
      .catch(function (error) {
        // Retry status itself: an initial failure has no runnable state yet.
        onError(error, generation, readStatus);
      });
  }

  function control(action) {
    if (controlActive || (action === "cancel" && !window.confirm(config.i18n.confirmCancel))) {
      return;
    }
    epoch += 1;
    var generation = epoch;
    clearTimer();
    controlActive = true;
    setControlStates();
    request("ritriever_backfill_" + action, generation)
      .then(function (state) {
        controlActive = false;
        if (!state) {
          return;
        }
        runAllowed = action === "resume";
        setControlStates();
        startWorkers();
      })
      .catch(function (error) {
        controlActive = false;
        setMessage(config.i18n.retrying.replace("%s", error.message));
        setControlStates();
        // The operation may have reached the server; reconcile instead of guessing.
        readStatus();
      });
  }

  Array.prototype.forEach.call(controls, function (button) {
    button.addEventListener("click", function () {
      control(button.getAttribute("data-ritriever-control"));
    });
  });
  setControlStates();
  readStatus();
})();
