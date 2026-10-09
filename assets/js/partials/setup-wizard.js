(function () {


  // =============== Templates (cache once) ===============
  let templateOptions = '<option value="">Select Template</option>';

  function buildTemplateOptions(templates) {
    let html = '<option value="">Select Template</option>';
    if (Array.isArray(templates)) {
      templates.forEach(t => {
        const name = (t && t.name) ? String(t.name) : '';
        const slug = (t && t.slug) ? String(t.slug) : '';
        html += `<option value="${slug.replace(/"/g, '&quot;')}">${name.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</option>`;
      });
    }
    return html;
  }

  function populateAllTemplateSelects() {
    document.querySelectorAll('.schema-template-select').forEach(sel => {
      sel.innerHTML = templateOptions;
    });
  }

  // fetch('api/get_all_templates.php')
  //   .then(r => r.json())
  //   .then(data => {
  //     templateOptions = buildTemplateOptions(Array.isArray(data) ? data : []);
  //     populateAllTemplateSelects();
  //   })
  //   .catch(() => {
  //     templateOptions = buildTemplateOptions([]);
  //     populateAllTemplateSelects();
  //   });

  // =============== Add custom schema row ===============
  const addSchemaBtn = document.getElementById('add-schema-btn');
  if (addSchemaBtn) {
    addSchemaBtn.addEventListener('click', function () {
      const container = document.querySelector('.space-y-4');
      const row = document.createElement('div');
      row.className = 'schema-row flex items-center justify-between border-b pb-4 gap-4';
      row.innerHTML = `
        <input type="text" name="custom_schemas[]" placeholder="Schema Name"
               class="form-control w-1/2 text-base font-medium text-neutral-700 dark:text-neutral-100">
        <select class="schema-template-select form-control w-1/2" name="custom_template[]">
          ${templateOptions}
        </select>`;
      container.appendChild(row);
    });
  }

  // =============== Toggle -> enable/disable template select ===============
  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('schema-toggle')) return;
    const row = e.target.closest('.schema-row');
    const sel = row ? row.querySelector('.schema-template-select') : null;
    if (!sel) return;
    const on = e.target.checked;
    sel.disabled = !on;
    if (!on) {
      sel.value = '';
      sel.classList.remove('is-invalid');
    }
  });

  // =============== Wizard nav (Next/Back) ===============
  $(document).on('click', '.form-wizard-next-btn', function () {
    const $btn = $(this);
    const $parent = $btn.parents('.wizard-fieldset');
    const $next = $parent.next('fieldset');
    const $step = $('.form-wizard-list__item.active');

    let isValid = true;

    // Step 3 (schemas) detection: has .schema-row elements
    if ($parent.find('.schema-row').length > 0) {
      $parent.find('.schema-row').each(function () {
        const $row = $(this);
        const $toggle = $row.find('.schema-toggle');
        const $sel = $row.find('.schema-template-select');

        if ($toggle.length) {
          // built-in row: only validate if enabled
          if ($toggle.is(':checked')) {
            if (!$sel.val()) {
              isValid = false;
              $sel.addClass('is-invalid');
            } else {
              $sel.removeClass('is-invalid');
            }
          } else {
            // if off, clear any previous error
            $sel.removeClass('is-invalid');
          }
        } else {
          // custom row: if any side filled, both required
          const $input = $row.find('input[type="text"]');
          const nameVal = ($input.val() || '').trim();
          const tmplVal = $sel.val() || '';

          if (nameVal !== '' || tmplVal !== '') {
            if (nameVal === '' || tmplVal === '') {
              isValid = false;
              $input.addClass('is-invalid');
              $sel.addClass('is-invalid');
            } else {
              $input.removeClass('is-invalid');
              $sel.removeClass('is-invalid');
            }
          } else {
            // nothing filled: ensure no stale errors
            $input.removeClass('is-invalid');
            $sel.removeClass('is-invalid');
          }
        }
      });

      if (!isValid) {
        alert('Please complete template selection for all enabled or custom schemas.');
        return;
      }

      // Save then move next
      $.ajax({
        url: 'api/save_schemas_settings.php',
        method: 'POST',
        data: $('form').serialize(),
        dataType: 'json'
      }).done(function (json) {
        if (!json || json.success !== true) {
          alert('Failed to save schema settings');
          return;
        }

        // After successful save, call the next API
        $.ajax({
          url: 'api/get_list_site_schema.php',
          method: 'POST',
          dataType: 'json'
        }).done(function (json) {
          if (!json.success) {
            alert('Failed to import products: ' + (json.error || 'Unknown error'));
          } else {
            alert(`Imported ${json.products_upserted} products.`);
          }
        }).fail(function (xhr) {
          alert('Error while importing products: ' + (xhr.responseText || 'Unknown error'));
        });
        // advance
        $parent.removeClass('show').addClass('hidden');
        $next.removeClass('hidden').addClass('show');
        $step.removeClass('active').next().addClass('active');
      }).fail(function (xhr) {
        console.error('Save error:', xhr.status, xhr.getResponseHeader('content-type'));
        console.log('Response:', (xhr.responseText || '').slice(0, 1000));
        alert('Invalid server response');
      });

      return; // stop here for schema step
    }

    // Normal required validation for other steps
    $parent.find('.wizard-required').each(function () {
      if (!$(this).val()) {
        isValid = false;
        $(this).addClass('is-invalid');
      } else {
        $(this).removeClass('is-invalid');
      }
    });

    if (!isValid) {
      alert('Please fill out all required fields.');
      return;
    }

    // advance
    $parent.removeClass('show').addClass('hidden');
    $next.removeClass('hidden').addClass('show');
    $step.removeClass('active').next().addClass('active');
  });

  $(document).on('click', '.form-wizard-previous-btn', function () {
    const $parent = $(this).parents('.wizard-fieldset');
    const $prev = $parent.prev('fieldset');
    const $step = $('.form-wizard-list__item.active');
    $parent.removeClass('show').addClass('hidden');
    $prev.removeClass('hidden').addClass('show');
    $step.removeClass('active').prev().addClass('active');
  });

})();