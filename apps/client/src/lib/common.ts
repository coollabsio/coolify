import { addToast } from './store';

export const asyncSleep = (delay: number) => new Promise((resolve) => setTimeout(resolve, delay));

export function errorNotification(error: any | { message: string }): void {
	if (error instanceof Error) {
		addToast({
			message: error.message,
			type: 'error'
		});
	} else {
		addToast({
			message: error,
			type: 'error'
		});
	}
}
export function getRndInteger(min: number, max: number) {
	return Math.floor(Math.random() * (max - min + 1)) + min;
}
