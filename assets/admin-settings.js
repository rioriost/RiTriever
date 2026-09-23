(function () {
  "use strict";

  var provider = document.getElementById("ritriever-provider");
  var openAiModel = document.getElementById("ritriever-openai-model");
  var dimensions = document.getElementById("ritriever-dimensions");
  var customPreset = document.getElementById("ritriever-custom-preset");
  var customEndpoint = document.getElementById("ritriever-custom-endpoint");
  var customModel = document.getElementById("ritriever-custom-model");
  var customFormat = document.getElementById("ritriever-custom-format");
  var providerRows = document.querySelectorAll("[data-provider-row]");
  var normalization = document.getElementById("ritriever-japanese-normalization");
  var normalizationRows = document.querySelectorAll("[data-normalization-row]");

  function syncProviderRows() {
    if (!provider) {
      return;
    }
    Array.prototype.forEach.call(providerRows, function (row) {
      var providers = (row.getAttribute("data-provider-row") || "").split(/\s+/);
      row.style.display = providers.indexOf(provider.value) !== -1 ? "" : "none";
    });
    if (customPreset) {
      var firstVisible = null;
      Array.prototype.forEach.call(customPreset.options, function (option) {
        var optionProvider = option.getAttribute("data-provider") || "custom_http";
        var visible =
          option.value === "custom" ||
          provider.value === "custom_http" || optionProvider === provider.value;
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible && !firstVisible) {
          firstVisible = option;
        }
      });
      if (
        customPreset.selectedOptions.length &&
        customPreset.selectedOptions[0].disabled &&
        firstVisible
      ) {
        customPreset.value = firstVisible.value;
      }
    }
    if (provider.value === "openai" && openAiModel && dimensions) {
      var option = openAiModel.options[openAiModel.selectedIndex];
      dimensions.value =
        option && option.getAttribute("data-dimensions")
          ? option.getAttribute("data-dimensions")
          : "1536";
    }
  }

  function syncCustomPresetFields() {
    if (!customPreset || customPreset.value === "custom") {
      return;
    }
    var option = customPreset.options[customPreset.selectedIndex];
    if (!option) {
      return;
    }
    if (customEndpoint && option.getAttribute("data-endpoint")) {
      customEndpoint.value = option.getAttribute("data-endpoint");
    }
    if (customModel && option.getAttribute("data-model")) {
      customModel.value = option.getAttribute("data-model");
    }
    if (dimensions && option.getAttribute("data-dimensions")) {
      dimensions.value = option.getAttribute("data-dimensions");
    }
    if (customFormat && option.getAttribute("data-format")) {
      customFormat.value = option.getAttribute("data-format");
    }
  }

  function syncNormalizationRows() {
    var visible = normalization ? normalization.checked : false;
    Array.prototype.forEach.call(normalizationRows, function (row) {
      row.style.display = visible ? "" : "none";
    });
  }

  if (provider) {
    provider.addEventListener("change", function () {
      syncProviderRows();
      if (provider.value === "openai" || !customPreset) {
        return;
      }
      if (provider.value === "custom_http") {
        customPreset.value = "custom";
        return;
      }
      var options = customPreset.options;
      for (var i = 0; i < options.length; i += 1) {
        if (options[i].getAttribute("data-provider") === provider.value) {
          customPreset.value = options[i].value;
          break;
        }
      }
      syncCustomPresetFields();
    });
  }
  if (openAiModel) {
    openAiModel.addEventListener("change", syncProviderRows);
  }
  if (normalization) {
    normalization.addEventListener("change", syncNormalizationRows);
  }
  if (customPreset) {
    customPreset.addEventListener("change", syncCustomPresetFields);
  }
  syncProviderRows();
  syncNormalizationRows();
})();
