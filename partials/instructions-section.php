<div class="card border-0 overflow-hidden">
  <div class="card-header border-0 bg-cstm-primary-gradient-light drk-bg-cstm-primary-gradient-light cstm-inline-space cstm-block-space">
    <div class="grid grid-cols-1 md:grid-cols-12 items-center gap-y-10 md:gap-x-10">
      <div class="col-span-12 md:col-span-7">
        <h4 class="text-3xl font-bold text-neutral-900 dark:text-white mb-6">
          App instructions & guides
        </h4>
        <p class="text-base leading-relaxed text-neutral-600 dark:text-neutral-300">
          Step-by-step help for installing, configuring, and using the
          <span class="font-semibold text-cstm-primary">Google Search Console</span> app on your Ecwid store.
        </p>
      </div>
      <div class="col-span-12 md:col-span-5 flex justify-center md:justify-end">
        <img src="assets/images/instructions.jpg" alt=""
          class="w-auto max-h-full md:max-h-[200px] lg:max-h-[200px] object-cover rounded-xl shadow-md">
      </div>
    </div>
  </div>

  <div class="card-body responsive-padding-40-150 cstm-inline-space cstm-block-space">
    <div class="grid grid-cols-1 xl:grid-cols-12 items-start gap-y-6 xl:gap-x-6">
      <div class="col-span-12 lg:col-span-4">
        <ul class="flex flex-wrap text-sm font-medium text-center active-text-tab nav flex-col nav-pills bg-white dark:bg-neutral-800 shadow-sm rounded-xl border dark:border-neutral-600 overflow-hidden"
          id="vertical-tab" role="tablist">
        </ul>
      </div>
      <div class="col-span-12 lg:col-span-8">
        <div id="vertical-tab-content"></div>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    fetch('./api/instruction.php')
      .then(r => r.json())
      .then(docsByCategory => {
        const tabsContainer = document.getElementById('vertical-tab');
        const contentContainer = document.getElementById('vertical-tab-content');
        let firstTab = true;

        // Helper: open first accordion item inside a given panel
        function openFirstAccordion(panel) {
          if (!panel) return;

          // Close all items in this panel
          panel.querySelectorAll('button[data-accordion-target]').forEach(btn => {
            btn.setAttribute('aria-expanded', 'false');
            const icon = btn.querySelector('span i');
            if (icon) {
              icon.classList.remove('ri-subtract-line');
              if (!icon.classList.contains('ri-add-line')) {
                icon.classList.add('ri-add-line');
              }
            }
          });
          panel.querySelectorAll('[id^="accordion-collapse-body-"]').forEach(body => {
            if (!body.classList.contains('hidden')) {
              body.classList.add('hidden');
            }
          });

          // Open the first one
          const firstButton = panel.querySelector('button[data-accordion-target]');
          if (!firstButton) return;

          const targetSelector = firstButton.getAttribute('data-accordion-target');
          const body = panel.querySelector(targetSelector);
          if (!body) return;

          body.classList.remove('hidden');
          firstButton.setAttribute('aria-expanded', 'true');
          const icon = firstButton.querySelector('span i');
          if (icon) {
            icon.classList.remove('ri-add-line');
            icon.classList.add('ri-subtract-line');
          }
        }

        for (const category in docsByCategory) {
          if (!docsByCategory.hasOwnProperty(category)) continue;
          const sanitizedCategory = category.toLowerCase().replace(/[ &]/g, '-');
          const isFirstCategory = firstTab;

          // Tab button
          const tabLi = document.createElement('li');
          tabLi.setAttribute('role', 'presentation');
          tabLi.classList.add('border-b', 'border-neutral-200', 'dark:border-neutral-600', 'last:border-b-0');

          const tabButton = document.createElement('button');
          tabButton.classList.add(
            'block', 'py-3', 'px-4', 'w-full', 'text-base', 'text-start', 'font-medium',
            'transition-colors', 'duration-200', 'hover:bg-neutral-50',
            'hover:text-cstm-primary-80', 'capitalize', 'dark:bg-gray-800'
          );
          tabButton.id = `vertical-${sanitizedCategory}-tab`;
          tabButton.type = 'button';
          tabButton.setAttribute('role', 'tab');
          tabButton.setAttribute('aria-controls', `vertical-${sanitizedCategory}`);
          tabButton.setAttribute('data-tabs-target', `#vertical-${sanitizedCategory}`);
          tabButton.textContent = category;

          if (firstTab) {
            tabButton.classList.add('text-cstm-primary', 'bg-cstm-primary-10');
            tabButton.setAttribute('aria-selected', 'true');
          } else {
            tabButton.setAttribute('aria-selected', 'false');
          }

          tabLi.appendChild(tabButton);
          tabsContainer.appendChild(tabLi);

          // Content pane
          const contentDiv = document.createElement('div');
          contentDiv.classList.add('rounded-lg', 'dark:bg-gray-900');
          if (!firstTab) contentDiv.classList.add('hidden');
          contentDiv.id = `vertical-${sanitizedCategory}`;
          contentDiv.setAttribute('role', 'tabpanel');
          contentDiv.setAttribute('aria-labelledby', `vertical-${sanitizedCategory}-tab`);

          // Accordion
          const accordionDiv = document.createElement('div');
          accordionDiv.id = `accordion-collapse-${sanitizedCategory}`;
          accordionDiv.setAttribute('data-accordion', 'collapse');

          docsByCategory[category].forEach((doc, index) => {
            const accordionItem = document.createElement('div');
            accordionItem.classList.add(
              'accordion-item', 'cstm-accordion-item', 'border', 'border-neutral-200',
              'dark:border-neutral-600', 'mb-2', 'last:mb-0', 'rounded-2xl', 'shadow-sm', 'dark:bg-gray-800'
            );

            // Header
            const headingDiv = document.createElement('div');
            headingDiv.id = `accordion-collapse-heading-${sanitizedCategory}-${index+1}`;

            const button = document.createElement('button');
            button.type = 'button';
            button.classList.add(
              'flex', 'items-center', 'justify-between', 'w-full', 'text-base', 'font-medium',
              'text-neutral-800', 'px-5', 'py-4', 'rounded-2xl', 'hover:bg-neutral-50',
              'transition-colors', 'duration-200', 'dark:hover:bg-neutral-800'
            );
            button.setAttribute('data-accordion-target', `#accordion-collapse-body-${sanitizedCategory}-${index+1}`);
            button.setAttribute('aria-controls', `accordion-collapse-body-${sanitizedCategory}-${index+1}`);

            const titleSpan = document.createElement('span');
            titleSpan.classList.add('text-start');
            titleSpan.textContent = doc.title;

            const iconSpan = document.createElement('span');
            iconSpan.className =
              'w-6 h-6 flex justify-center items-center border border-cstm-primary rounded-full text-cstm-primary text-base shrink-0';
            iconSpan.innerHTML = '<i class="ri-add-line"></i>';

            button.appendChild(titleSpan);
            button.appendChild(iconSpan);
            headingDiv.appendChild(button);

            // Body
            const bodyDiv = document.createElement('div');
            bodyDiv.id = `accordion-collapse-body-${sanitizedCategory}-${index+1}`;
            bodyDiv.setAttribute('aria-labelledby', headingDiv.id);
            bodyDiv.classList.add('hidden');

            const bodyInnerDiv = document.createElement('div');
            bodyInnerDiv.classList.add('p-5', 'text-neutral-700', 'dark:text-neutral-300', 'leading-relaxed');

            if (doc.content && /<\/?[a-z][\s\S]*>/i.test(doc.content)) {
              bodyInnerDiv.innerHTML = doc.content;
            } else {
              bodyInnerDiv.innerHTML = `<p>${(doc.content || '').replace(/\n/g,'<br>')}</p>`;
            }

            if (doc.slug) {
              const anchor = document.createElement('a');
              anchor.href = `#${doc.slug}`;
              anchor.id = doc.slug;
              anchor.className = 'block text-xs mt-3 opacity-70 text-cstm-primary drk-text-cstm-primary hidden';
              anchor.textContent = `#${doc.slug}`;
              bodyInnerDiv.appendChild(anchor);
            }

            bodyDiv.appendChild(bodyInnerDiv);
            accordionItem.appendChild(headingDiv);
            accordionItem.appendChild(bodyDiv);
            accordionDiv.appendChild(accordionItem);

            // Click toggle
            button.addEventListener('click', () => {
              const willOpen = bodyDiv.classList.contains('hidden');
              bodyDiv.classList.toggle('hidden', !willOpen);
              button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
              const icon = button.querySelector('span i');
              if (icon) {
                icon.classList.toggle('ri-add-line', !willOpen);
                icon.classList.toggle('ri-subtract-line', willOpen);
              }
            });
          });

          contentDiv.appendChild(accordionDiv);
          contentContainer.appendChild(contentDiv);

          // After first tab's panel is created, open its first item
          if (isFirstCategory) {
            openFirstAccordion(contentDiv);
          }

          firstTab = false;
        }

        // TAB SWITCHING
        const tabButtons = tabsContainer.querySelectorAll('button[role="tab"]');
        tabButtons.forEach(tabButton => {
          tabButton.addEventListener('click', function() {
            tabButtons.forEach(b => {
              b.classList.remove('text-cstm-primary', 'bg-cstm-primary-10');
              b.setAttribute('aria-selected', 'false');
              const pane = document.querySelector(b.getAttribute('data-tabs-target'));
              if (pane) pane.classList.add('hidden');
            });

            this.classList.add('text-cstm-primary', 'bg-cstm-primary-10');
            this.setAttribute('aria-selected', 'true');
            const targetSelector = this.getAttribute('data-tabs-target');
            const targetPane = document.querySelector(targetSelector);
            if (targetPane) {
              targetPane.classList.remove('hidden');
              // OPEN FIRST ACCORDION ITEM IN THIS TAB
              openFirstAccordion(targetPane);
            }
          });
        });
      })
      .catch(err => console.error('Error fetching Instructions:', err));
  });
</script>