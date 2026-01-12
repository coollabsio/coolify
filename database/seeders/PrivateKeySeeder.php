<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use Illuminate\Database\Seeder;

class PrivateKeySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PrivateKey::create([
            'uuid' => 'ssh',
            'team_id' => 0,
            'name' => 'Testing Host Key',
            'description' => 'This is a test docker container',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
',
        ]);

        PrivateKey::create([
            'uuid' => 'github-key',
            'team_id' => 0,
            'name' => 'development-github-app',
            'description' => 'This is the key for using the development GitHub app',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAstJo/SfYh3tquc2BA29a1X3pdPpXazRgtKsb5fHOwQs1rE04
VyJYW6QCToSH4WS1oKt6iI4ma4uivn8rnkZFdw3mpcLp2ofcoeV3YPKX6pN/RiJC
if+g8gCaFywOxy2pjXOLPZeFJSXFqc4UOymbhESUyDnMfk4/RvnubMiv3jINo4Ow
4Tv7tRzAdMlMrx3hEhi142oQuyl1kc4WQOM9cAV0bd+62ga3EYSnsWTnC9AaFtWk
eGC5w/7knHJ5QZ9tKApkG3/29vJXY7WwCRUROEHqkvQhRDP0uqRPBdR48iG87Dwq
ePa6TodkFaVfyHS/OUZzRiTn6MOSyQQFg0QIIwIDAQABAoIBAQCsmGebSJU2lwl4
0oAeZ6E9hG0LagFsSL66QpkHxO9w5bflWRbzCwRLVy6eyE46XzDrJfd7y/ALR1hK
E4ZvGpY7heBDx7BdK1rprAggO6YjVD+42qJsfZ3DVo9jpDOTTWBkVcxkI1Xwd9ej
wHNIcy1WabdM1nSoyC9M+ziEKOOOShXc5Q6e+zEzSBbwjc1fvvXZOH4VXZZ1DllE
xGu0jFS23TLnXATxh8SdfYgnvfZgB5n72P9m/lj3FmkuJq57DLZhBwN3Zd4wom03
K7/J4K2Ssnjdv/HjVgrRgpMv7oMxfclN/Aiq878Ue4Mav6LjnLENyHbyR0WxQjY6
lZ7UMEeJAoGBAOCGepk3rCMFa3a6GagN6lYzAkLxB5y0PsefiDo6w+PeEj1tUvSd
aQkiP7uvUC7a5GNp9yE8W79/O1jJXYJq15kMBpUshzfgdzyzDDCj+qvm6nbTWtP9
rP30h81R+NGdOStgs0OVZSjMWnIoii3Rv3UV4+iQXZd67+wd/kbTWtWVAoGBAMvj
xv4wjt7OwtK/6oAhcNd2V9EUQp6PPpMkUyPicWdsLsoNOcuTpWvEc0AomdIGGjgI
AIor1ggCxjEhbCDaZucOFUghciUup+PjyQyQT+3bjvCWuUmi0Vt51G7RE0jjZjQt
2+W9V4yDcJ5R5ow6veYvT0ZOjVTScDYowTBulgjXAoGBALFxVl7UotQiqmVwempY
ZQSu13C0MIHl6V+2cuEiJEJn9R5a0h7EcIhpatkXmlUNZUY0Lr0ziIb1NJ/ctGwn
qDAqUuF+CXddjJ6KGm4uiiNlIZO7QaMcbqVdph3cVLrEeLQRfltBLGtr5WcnJt1D
UP5lyHK59V2MKSUAJz8uNjFpAoGAL5fR4Y/wKa5V5+AImzQzJPho81MpYd3KG4rF
JYE8O4oTOfLwZMboPEm1JWrUzSPDhwTHK3mkEmajYOCOXvTcRF8TNK0p+ef0JMwN
KDOflMRFj39/bOLmv9Wmct+3ArKiLtftlqkmAJTF+w7fJCiqH0s31A+OChi9PMcy
oV2PBC0CgYAXOm08kFOQA+bPBdLAte8Ga89frh6asH/Z8ucfsz9/zMMG/hhq5nF3
7TItY9Pblc2Fp805J13G96zWLX4YGyLwXXkYs+Ae7QoqjonTw7/mUDARY1Zxs9m/
a1C8EDKapCw5hAhizEFOUQKOygL8Ipn+tmEUkORYdZ8Q8cWFCv9nIw==
-----END RSA PRIVATE KEY-----',
            'is_git_related' => true,
        ]);
    }
}
