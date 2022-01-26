import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import relativeTime from 'dayjs/plugin/relativeTime.js';
dayjs.extend(utc);
dayjs.extend(relativeTime);

export { dayjs };
