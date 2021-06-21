import mongoose from 'mongoose';
const { Schema } = mongoose;
export interface IUser extends Document {
	email: string;
	avatar?: string;
	uid: string;
	type: string;
	password: string;
}

const UserSchema = new Schema({
	email: { type: String, required: true, unique: true },
	avatar: { type: String },
	uid: { type: String, required: true },
	type: { type: String, required: true, default: 'github' },
	password: { type: String }
});

UserSchema.set('timestamps', true);

export default mongoose.models['user'] || mongoose.model('user', UserSchema);
