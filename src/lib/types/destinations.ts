export type CreateDockerDestination = {
	name: string;
	engine: string;
	remoteEngine: boolean;
	network: string;
	isCoolifyProxyUsed: boolean;
	teamId: string;
};
