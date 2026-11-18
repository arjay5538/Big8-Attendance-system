const group = document.querySelector('#group');
const person = document.querySelector('#person');
const search = document.querySelector('#person-search');

if (group && person && search) {
    const people = Array.from(person.options).slice(1);
    const placeholder = person.options[0];

    function filterPeople() {
        const selected = person.value;
        const query = search.value.trim().toLocaleLowerCase();
        const matching = people.filter(option => {
            const matchesGroup = group.value === 'all'
                || (group.value === 'employees' ? option.dataset.employee === '1' : option.dataset.collector === '1');
            return matchesGroup && option.textContent.toLocaleLowerCase().includes(query);
        });
        placeholder.textContent = matching.length ? 'Choose a person' : 'No matching people';
        person.replaceChildren(placeholder, ...matching);
        person.value = matching.some(option => option.value === selected) ? selected : '';
    }

    search.hidden = false;
    search.addEventListener('input', filterPeople);
    search.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            person.focus();
        }
    });
    group.addEventListener('change', () => {
        search.value = '';
        filterPeople();
    });
    filterPeople();
}

document.querySelector('#print-report')?.addEventListener('click', () => window.print());
document.querySelector('#clear-import')?.addEventListener('submit', event => {
    if (!window.confirm('Clear the currently imported attendance log?')) event.preventDefault();
});
for (const form of document.querySelectorAll('.remove-entry')) {
    form.addEventListener('submit', event => {
        if (!window.confirm('Remove this entry from the roster?')) event.preventDefault();
    });
}

const calendar = document.querySelector('#date-calendar');

if (calendar && typeof calendar.showModal === 'function') {
    const today = calendar.dataset.today;
    const currentYear = today.slice(0, 4);
    const importedYears = calendar.dataset.years.split(',').filter(Boolean);
    const title = calendar.querySelector('h2');
    const monthSelect = calendar.querySelector('#calendar-month');
    const yearSelect = calendar.querySelector('#calendar-year');
    const days = calendar.querySelector('.calendar-days');
    const monthSteps = calendar.querySelectorAll('[data-calendar-step]');
    const dateLabel = new Intl.DateTimeFormat('en-PH', { dateStyle: 'long', timeZone: 'UTC' });
    const shortLabel = new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'
    });
    const monthLabel = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' });
    const fields = Array.from(document.querySelectorAll('[data-date-field]'), trigger => ({
        trigger,
        input: document.getElementById(trigger.dataset.dateField + '-date'),
        label: trigger.previousElementSibling.labels[0]
    }));

    let active;
    for (let month = 1; month <= 12; month++) {
        const date = new Date(Date.UTC(2024, month - 1, 1));
        monthSelect.add(new Option(monthLabel.format(date), String(month)));
    }

    function updateTrigger(field) {
        const label = field.input.value
            ? shortLabel.format(new Date(field.input.value))
            : 'Choose a date';
        field.trigger.firstElementChild.textContent = label;
        field.trigger.setAttribute('aria-label', field.label.textContent + ', ' + label);
    }

    function chooseDate(date) {
        active.input.value = date;
        updateTrigger(active);
        calendar.close();
    }

    function renderCalendar() {
        const year = yearSelect.value;
        const month = Number(monthSelect.value);
        const prefix = year + '-' + String(month).padStart(2, '0') + '-';
        const first = new Date(prefix + '01');
        const end = new Date(first);
        end.setUTCMonth(month, 0);
        for (const step of monthSteps) {
            const next = Number(step.dataset.calendarStep);
            const adjacentYear = String(Number(year) + next).padStart(4, '0');
            const crossesYear = month + next < 1 || month + next > 12;
            step.disabled = crossesYear && !Array.from(yearSelect.options).some(option => option.value === adjacentYear);
        }

        const cells = [];
        for (let day = 0; day < first.getUTCDay(); day++) {
            cells.push(document.createElement('span'));
        }
        for (let day = 1; day <= end.getUTCDate(); day++) {
            const date = prefix + String(day).padStart(2, '0');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'calendar-day';
            button.textContent = day;
            button.setAttribute('aria-label', dateLabel.format(new Date(date)));
            button.setAttribute('aria-pressed', String(date === active.input.value));
            if (date === today) button.setAttribute('aria-current', 'date');
            button.addEventListener('click', () => chooseDate(date));
            cells.push(button);
        }
        days.replaceChildren(...cells);
    }

    for (const field of fields) {
        updateTrigger(field);
        field.input.hidden = true;
        field.input.required = false;
        field.label.htmlFor = field.trigger.id = field.input.id + '-trigger';
        field.trigger.hidden = false;
        field.trigger.addEventListener('click', () => {
            active = field;
            title.textContent = 'Choose ' + field.label.textContent.toLowerCase() + ' date';
            const [year, month] = (field.input.value || today).split('-');
            const years = new Set([currentYear, ...importedYears.sort().reverse(), year]);
            yearSelect.replaceChildren(...Array.from(years, value => new Option(value, value)));
            yearSelect.value = year;
            monthSelect.value = String(Number(month));
            renderCalendar();
            calendar.showModal();
            const selectedDay = days.querySelector('[aria-pressed="true"]');
            (selectedDay || monthSelect).focus();
        });
    }

    monthSelect.addEventListener('change', renderCalendar);
    yearSelect.addEventListener('change', renderCalendar);
    for (const step of monthSteps) {
        step.addEventListener('click', () => {
            const direction = Number(step.dataset.calendarStep);
            const month = Number(monthSelect.value) + direction;
            if (month < 1 || month > 12) {
                yearSelect.value = String(Number(yearSelect.value) + direction).padStart(4, '0');
            }
            monthSelect.value = String(month < 1 ? 12 : month > 12 ? 1 : month);
            renderCalendar();
            if (step.disabled) monthSelect.focus();
        });
    }
    calendar.querySelector('[data-calendar-today]').addEventListener('click', () => chooseDate(today));
    calendar.querySelector('[data-calendar-close]').addEventListener('click', () => calendar.close());
    calendar.addEventListener('close', () => active.trigger.focus());
}
