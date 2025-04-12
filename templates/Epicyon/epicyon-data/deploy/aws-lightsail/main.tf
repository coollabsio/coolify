resource "aws_lightsail_static_ip" "epicyon_static_ip" {
  name = "epicyon"
}
resource "aws_lightsail_static_ip_attachment" "for_epicyon" {
  static_ip_name = aws_lightsail_static_ip.epicyon_static_ip.id
  instance_name  = aws_lightsail_instance.epicyon.id
}

resource "aws_lightsail_key_pair" "ssh_key_pair" {
  name       = "epicyon_key"
  public_key = var.publickey
}

resource "aws_lightsail_instance" "epicyon" {
  name              = var.instance_name
  availability_zone = "us-east-1a"
  blueprint_id      = "ubuntu_20_04"
  bundle_id         = "nano_2_0"
  key_pair_name     = var.key

}

resource "aws_lightsail_domain" "epicyon_domain" {
  domain_name = var.domain
}

resource "aws_lightsail_domain_entry" "epicyon" {
  depends_on = [aws_lightsail_static_ip.epicyon_static_ip]
  domain_name = aws_lightsail_domain.epicyon_domain.domain_name
  name        = var.epicyon_sub_domain
  type        = "A"
  target      = aws_lightsail_static_ip.epicyon_static_ip.ip_address
}

resource "null_resource" "null_resource_epicyon" {
  depends_on = [aws_lightsail_domain_entry.epicyon]
  triggers = {
    id = timestamp()
  }
   connection {
    agent       = false
    type        = "ssh"
    host        = aws_lightsail_static_ip.epicyon_static_ip.ip_address
    private_key = file(var.private_key)
    user        = aws_lightsail_instance.epicyon.username
  }
  provisioner "file" {
    source      = "./templates/startup.sh"
    destination = "~/startup.sh"
  }
  provisioner "remote-exec" {
    inline = [
      "chmod +x ~/startup.sh",
      "export domain=${var.epicyon_sub_domain}",
      "export email=${var.email}",
      "bash ~/startup.sh"
    ]
  }
}

