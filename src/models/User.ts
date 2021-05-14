import mongoose from 'mongoose';
const { Schema } = mongoose;
export interface IUser extends Document {
	email: string;
	avatar?: string;
	uid: string;
}

const UserSchema = new Schema({
	email: { type: String, required: true, unique: true },
	avatar: { type: String },
	uid: { type: String, required: true }
});

UserSchema.set('timestamps', true);

export default mongoose.model('user', UserSchema);
