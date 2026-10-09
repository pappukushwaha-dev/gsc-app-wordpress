<?php
$title = 'Faq';
$subTitle = 'Faq';
include './partials/layouts/layoutTop.php';
?>

<div class="card border-0 overflow-hidden">

    <div class="card-header border-0 bg-cstm-primary-gradient-light drk-bg-cstm-primary-gradient-light cstm-inline-space cstm-block-space">
        <div class="grid grid-cols-1 md:grid-cols-12 items-center  gap-y-10 md:gap-x-10">

            <div class="col-span-12 md:col-span-7">
                <h4 class="text-3xl font-bold text-neutral-900 dark:text-white mb-6">
                    Frequently asked questions.
                </h4>
                <p class="text-base leading-relaxed text-neutral-600 dark:text-neutral-300">
                    Get answers to the most common questions about installing, configuring,
                    and using the <span class="font-semibold text-cstm-primary">Google Search Console</span>
                    app on your Ecwid store.
                </p>
            </div>

            <div class="col-span-12 md:col-span-5 flex justify-center md:justify-end">
                <img src="assets/images/faq-img.jpg" alt=""
                    class="w-auto max-h-full md:max-h-[200px] lg:max-h-[200px] object-cover rounded-xl shadow-md">
            </div>

        </div>
    </div>

    <div class="card-body responsive-padding-40-150 cstm-inline-space cstm-block-space">
        <div class="grid grid-cols-1 xl:grid-cols-12 items-start gap-y-6 xl:gap-x-6">
            <div class="col-span-12 lg:col-span-4">
                <ul class="flex flex-wrap text-sm font-medium text-center active-text-tab nav flex-col nav-pills bg-white dark:bg-neutral-800 shadow-sm rounded-xl border dark:border-neutral-600  overflow-hidden"
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
        fetch('./api/faq.php')
            .then(response => response.json())
            .then(faqsByCategory => {
                const tabsContainer = document.getElementById('vertical-tab');
                const contentContainer = document.getElementById('vertical-tab-content');
                let firstTab = true;
                for (const category in faqsByCategory) {
                    if (faqsByCategory.hasOwnProperty(category)) {
                        const sanitizedCategory = category.toLowerCase().replace(/[ &]/g, '-');

                        // Create tab button
                        const tabLi = document.createElement('li');
                        tabLi.setAttribute('role', 'presentation');
                        tabLi.classList.add('border-b', 'border-neutral-200', 'dark:border-neutral-600', 'last:border-b-0');

                        const tabButton = document.createElement('button');
                        tabButton.classList.add(
                            'block', 'py-3', 'px-4', 'w-full', 'text-base', 'text-start', 'font-medium',
                            'transition-colors', 'duration-200', 'hover:bg-neutral-50',
                            'hover:text-cstm-primary-80', 'capitalize', 'dark:bg-gray-800'
                        );

                        tabButton.setAttribute('id', `vertical-${sanitizedCategory}-tab`);
                        tabButton.setAttribute('type', 'button');
                        tabButton.setAttribute('role', 'tab');
                        tabButton.setAttribute('aria-controls', `vertical-${sanitizedCategory}`);
                        tabButton.setAttribute('data-tabs-target', `#vertical-${sanitizedCategory}`);
                        tabButton.innerHTML = category;
                        if (firstTab) {
                            tabButton.classList.add('text-cstm-primary', 'bg-cstm-primary-10');
                            tabButton.setAttribute('aria-selected', 'true');
                        } else {
                            tabButton.setAttribute('aria-selected', 'false');
                        }
                        tabLi.appendChild(tabButton);
                        tabsContainer.appendChild(tabLi);

                        // Create content pane
                        const contentDiv = document.createElement('div');
                        contentDiv.classList.add('rounded-lg', 'dark:bg-gray-900');
                        if (!firstTab) contentDiv.classList.add('hidden');
                        contentDiv.setAttribute('id', `vertical-${sanitizedCategory}`);
                        contentDiv.setAttribute('role', 'tabpanel');
                        contentDiv.setAttribute('aria-labelledby', `vertical-${sanitizedCategory}-tab`);

                        // Accordion for FAQs
                        const accordionDiv = document.createElement('div');
                        accordionDiv.setAttribute('id', `accordion-collapse-${sanitizedCategory}`);
                        accordionDiv.setAttribute('data-accordion', 'collapse');

                        faqsByCategory[category].forEach((faq, index) => {
                            // Accordion for FAQ
                            const accordionItem = document.createElement('div');
                            accordionItem.classList.add('accordion-item', 'cstm-accordion-item', 'border', 'border-neutral-200',
                                'dark:border-neutral-600', 'mb-2', 'last:mb-0', 'rounded-2xl', 'shadow-sm', 'dark:bg-gray-800');
                            const headingDiv = document.createElement('div');
                            headingDiv.setAttribute('id', `accordion-collapse-heading-${sanitizedCategory}-${index + 1}`);
                            const questionButton = document.createElement('button');
                            questionButton.setAttribute('type', 'button');

                            // Header
                            questionButton.classList.add(
                                'flex', 'items-center', 'justify-between', 'w-full', 'text-base', 'font-medium',
                                'text-neutral-800', 'px-5', 'py-4', 'rounded-2xl', 'hover:bg-neutral-50', 'transition-colors', 'duration-200', 'dark:hover:bg-neutral-800'
                            );

                            questionButton.setAttribute('data-accordion-target', `#accordion-collapse-body-${sanitizedCategory}-${index + 1}`);
                            questionButton.setAttribute('aria-expanded', 'false');
                            questionButton.setAttribute('aria-controls', `accordion-collapse-body-${sanitizedCategory}-${index + 1}`);
                            questionButton.innerHTML = `<span class="text-start">${faq.question}</span>
                        <span class="w-6 h-6 flex justify-center items-center border border-cstm-primary rounded-full text-cstm-primary text-base shrink-0"><i class="ri-add-line"></i></span>`;
                            headingDiv.appendChild(questionButton);

                            const bodyDiv = document.createElement('div');
                            bodyDiv.setAttribute('id', `accordion-collapse-body-${sanitizedCategory}-${index + 1}`);
                            bodyDiv.classList.add('hidden');
                            bodyDiv.setAttribute('aria-labelledby', `accordion-collapse-heading-${sanitizedCategory}-${index + 1}`);
                            if (index === 0) {
                                bodyDiv.classList.remove('hidden');
                                questionButton.setAttribute('aria-expanded', 'true');
                            }

                            const bodyInnerDiv = document.createElement('div');
                            bodyInnerDiv.classList.add('p-5', 'text-neutral-700', 'dark:text-neutral-300', 'leading-relaxed');
                            bodyInnerDiv.innerHTML = `<p>${faq.answer.replace(/\n/g, '<br>')}</p>`;

                            bodyDiv.appendChild(bodyInnerDiv);

                            accordionItem.appendChild(headingDiv);
                            accordionItem.appendChild(bodyDiv);
                            accordionDiv.appendChild(accordionItem);

                            // Accordion open/close logic
                            questionButton.addEventListener('click', function() {
                                // Close all other accordions in the same tab
                                const allItems = accordionDiv.querySelectorAll('.accordion-item');
                                allItems.forEach(item => {
                                    const otherBody = item.querySelector('div[aria-labelledby]');
                                    const otherButton = item.querySelector('button');
                                    if (otherBody !== bodyDiv) {
                                        otherBody.classList.add('hidden');
                                        if (otherButton) {
                                            otherButton.setAttribute('aria-expanded', 'false');
                                            otherButton.querySelector('i')?.classList.replace('ri-subtract-line', 'ri-add-line');
                                        }
                                    }
                                });

                                // Toggle clicked accordion
                                const willOpen = bodyDiv.classList.contains('hidden');
                                bodyDiv.classList.toggle('hidden', !willOpen);
                                questionButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                                questionButton.querySelector('i')?.classList.replace(
                                    willOpen ? 'ri-add-line' : 'ri-subtract-line',
                                    willOpen ? 'ri-subtract-line' : 'ri-add-line'
                                );
                            });

                        });

                        contentDiv.appendChild(accordionDiv);
                        contentContainer.appendChild(contentDiv);

                        firstTab = false;
                    }
                }

                // TAB SWITCHING LOGIC
                const tabButtons = tabsContainer.querySelectorAll('button[role="tab"]');
                tabButtons.forEach(tabButton => {
                    tabButton.addEventListener('click', function() {
                        tabButtons.forEach(b => {
                            b.classList.remove('text-cstm-primary', 'bg-cstm-primary-10');
                            b.setAttribute('aria-selected', 'false');
                            document.querySelector(b.getAttribute('data-tabs-target')).classList.add('hidden');
                        });
                        this.classList.add('text-cstm-primary', 'bg-cstm-primary-10');
                        this.setAttribute('aria-selected', 'true');
                        document.querySelector(this.getAttribute('data-tabs-target')).classList.remove('hidden');
                    });
                });
            })
            .catch(error => console.error('Error fetching FAQs:', error));
    });
</script>

<?php include './partials/layouts/layoutBottom.php' ?>