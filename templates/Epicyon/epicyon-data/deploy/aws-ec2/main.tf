resource "aws_vpc" "epicyon_vpc" {
  cidr_block       = var.vpc_cidr_block

  tags = {
    Name = "epicyon_vpc"
  }
}

resource "aws_subnet" "epicyon_subnet" {
  vpc_id     = aws_vpc.epicyon_vpc.id
  cidr_block = var.subnet_cidr

  tags = {
    Name = "epicyon_subnet"
  }
}

resource "aws_internet_gateway" "epicyon_gw" {
  vpc_id = aws_vpc.epicyon_vpc.id

  tags = {
    Name = "epicyon_gw"
  }
}

resource "aws_route_table" "epicyon_route_table" {
  vpc_id = aws_vpc.epicyon_vpc.id

  route {
    cidr_block = var.route_cidr_block
    gateway_id = aws_internet_gateway.epicyon_gw.id
  }
}

resource "aws_route_table_association" "epicyon_route_table_association" {
  subnet_id      = aws_subnet.epicyon_subnet.id
  route_table_id = aws_route_table.epicyon_route_table.id
}

resource "aws_security_group" "epicyon_sg" {
  name        = "epicyon_sg"
  description = "Allow all incoming traffic"
  vpc_id      = aws_vpc.epicyon_vpc.id

  dynamic "ingress" {
    for_each = toset(var.domain == "" ? [8080] : [80, 443])
    content {
      cidr_blocks = [
        "0.0.0.0/0"
      ]
      from_port = ingress.value
      to_port   = ingress.value
      protocol  = "tcp"
    }
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

data "aws_ami" "ubuntu" {
  most_recent = true

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd/ubuntu-focal-20.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
  owners = ["099720109477"]
}

resource "aws_instance" "epicyon_web" {
  ami                         = data.aws_ami.ubuntu.id
  iam_instance_profile        = aws_iam_instance_profile.epicyon_instance_profile.id
  instance_type               = var.instance_type
  associate_public_ip_address = true
  subnet_id                   = aws_subnet.epicyon_subnet.id
  vpc_security_group_ids      = [aws_security_group.epicyon_sg.id]
  key_name                    = var.key_name
  tags = {
    Name = "epicyon_web"
  }
}

resource "aws_route53_record" "epicyon_route53" {
  zone_id = var.zone_id
  name    = var.domain
  type    = "A"
  ttl     = 300
  records = [aws_instance.epicyon_web.public_ip]
  depends_on = [aws_instance.epicyon_web]
}

resource "aws_iam_role" "epicyon_iam_role" {
  name = "epicyon_iam_role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Sid    = ""
        Principal = {
          Service = "ec2.amazonaws.com"
        }
      },
    ]
  })

resource "aws_iam_instance_profile" "epicyon_instance_profile" {
  name = var.profile
  role = aws_iam_role.epicyon_role.id
}

resource "aws_iam_policy_attachment" "epicyon" {
  name       = format("%s-attachment", epicyon)
  roles      = [aws_iam_role.epicyon_role.id]
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_eip" "epicyon" {
  instance = aws_instance.epicyon_web.id
  vpc      = true
}

resource "aws_eip_association" "epicyon" {
  instance_id   = aws_instance.epicyon_web.id
  allocation_id = aws_eip.elastic.id
}

resource "null_resource" "null_resource_epicyon" {
  depends_on=[aws_route53_record.epicyon_route53]
  triggers = {
    id = timestamp()
  }
   connection {
    agent       = false
    type        = "ssh"
    host        = [aws_instance.epicyon_web.public_ip]
    private_key = file(var.private_key)
    user        = "ubuntu"
  }
  provisioner "file" {
    source      = "./templates/startup.sh"
    destination = "~/startup.sh"
  }
  provisioner "remote-exec" {
    inline = [
      "chmod +x ~/startup.sh",
      "export domain=${var.epicyon_domain}",
      "export email=${var.email}",
      "bash ~/startup.sh"
    ]
  }
}
