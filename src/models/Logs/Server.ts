import mongoose from 'mongoose';
import { version } from '../../../package.json';
const { Schema, Document } = mongoose;

// export interface ILogsServer extends Document {
//   version: string;
//   type: string;
//   message: string;
//   stack: string;
//   seen: Boolean;
// }

const LogsServerSchema = new Schema({
	version: { type: String, default: version },
	type: { type: String, required: true },
	message: { type: String, required: true },
	stack: { type: String },
	seen: { type: Boolean, default: false }
});

LogsServerSchema.set('timestamps', { createdAt: 'createdAt', updatedAt: false });

export default mongoose.model('LogsServer', LogsServerSchema);
