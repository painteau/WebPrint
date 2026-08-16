(function () {
  'use strict';
  var dropzone = document.getElementById('dropzone');
  var input = document.getElementById('file');
  var hint = document.getElementById('dropzone-hint');
  if (!dropzone || !input || typeof DataTransfer === 'undefined') {
    return;
  }

  function setActive(active) {
    dropzone.classList.toggle('dropzone-active', active);
  }

  ['dragenter', 'dragover'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      e.stopPropagation();
      setActive(true);
    });
  });

  ['dragleave', 'dragend'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      e.stopPropagation();
      setActive(false);
    });
  });

  dropzone.addEventListener('drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    setActive(false);
    var files = e.dataTransfer && e.dataTransfer.files;
    if (!files || files.length === 0) {
      return;
    }
    input.files = files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  input.addEventListener('change', function () {
    if (input.files && input.files.length > 0 && hint) {
      hint.textContent = input.files[0].name;
    }
  });
})();
